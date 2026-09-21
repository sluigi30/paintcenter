<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\MessageAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photos in the customer <-> store thread.
 *
 * Two things are being protected here, and they are not the same thing. One is
 * that a photo survives the trip: stored, measured, ordered, served back. The
 * other is that it is only ever served to someone entitled to it - these are
 * customers' photographs, and the ids are a plain auto-increment.
 */
class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything writes to the default disk, which the service reads from
        // config - faking it here covers the service, the controller and the
        // sweeper without any of them needing a test-only branch.
        Storage::fake(config('filesystems.default'));
    }

    private function admin(string $email = 'admin@example.test'): User
    {
        return User::create([
            'first_name' => 'Store',
            'last_name'  => 'Admin',
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ]);
    }

    private function customer(string $email = 'customer@example.test'): User
    {
        return User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => 'customer',
        ]);
    }

    private function photo(string $name = 'wall.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    // ── Sending ────────────────────────────────────────────────────────────

    public function test_a_customer_can_send_photos_with_a_message(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'content'     => 'Is this the right shade?',
            'attachments' => [$this->photo('wall.jpg', 1200, 900)],
        ]);

        $response->assertCreated();

        $message = Message::first();
        $this->assertSame('Is this the right shade?', $message->content);
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments->first();
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame(1200, $attachment->width);
        $this->assertSame(900, $attachment->height);
        $this->assertTrue($attachment->exists(), 'The stored file should be on the disk.');

        // The disk is recorded per row, not resolved at render time - a store
        // half-way through a move to object storage has live files on both.
        $this->assertSame(config('filesystems.default'), $attachment->disk);
    }

    public function test_a_photo_can_be_sent_with_no_words_at_all(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'attachments' => [$this->photo()],
        ])->assertCreated();

        $this->assertNull(Message::first()->content);
    }

    public function test_a_message_with_neither_words_nor_photos_is_refused(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
        ])->assertStatus(422)->assertJsonValidationErrors('content');

        $this->assertSame(0, Message::count());
    }

    /**
     * Nothing in the system can display a HEIC: the panel is viewed in Chrome,
     * which does not render it, and this server has gd and no imagick, so it
     * cannot even be measured. Refusing it beats storing something nobody can
     * open.
     */
    public function test_formats_nothing_can_display_are_refused(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'attachments' => [UploadedFile::fake()->create('photo.heic', 400, 'image/heic')],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles('messages'));
    }

    public function test_more_than_five_photos_are_refused(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $photos = [];
        for ($i = 0; $i <= MessageAttachmentService::MAX_FILES; $i++) {
            $photos[] = $this->photo("photo-{$i}.jpg");
        }

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'attachments' => $photos,
        ])->assertStatus(422)->assertJsonValidationErrors('attachments');
    }

    // ── Ordering ───────────────────────────────────────────────────────────

    /**
     * The order photos were picked in is the order they must render in, on
     * every client. Same lesson as Product::variants(): an unordered read gets
     * answered from whichever index the planner likes.
     */
    public function test_attachment_order_is_preserved_in_the_thread_response(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'attachments' => [
                $this->photo('first.jpg'),
                $this->photo('second.jpg'),
                $this->photo('third.jpg'),
            ],
        ])->assertCreated();

        // Shuffle sort_order so the answer cannot come from insertion order.
        $attachments = MessageAttachment::orderBy('id')->get();
        $attachments[0]->update(['sort_order' => 2]);
        $attachments[2]->update(['sort_order' => 0]);

        $thread = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/messages/thread/{$admin->id}")
            ->json();

        $names = array_column($thread[0]['attachments'], 'original_name');

        $this->assertSame(['third.jpg', 'second.jpg', 'first.jpg'], $names);
    }

    // ── Who may look ───────────────────────────────────────────────────────

    /**
     * Built through the service rather than over HTTP on purpose. Sending it
     * as the customer would leave that customer as the acting user for the
     * rest of the test - actingAs() also switches the DEFAULT guard, so a
     * later actingAs($admin) would authenticate against sanctum and the panel
     * would see nobody. The fixture must not decide who is logged in.
     */
    private function attachmentFrom(User $customer, User $admin): MessageAttachment
    {
        MessageAttachmentService::send(
            senderId: $customer->id,
            receiverId: $admin->id,
            content: null,
            files: [$this->photo()],
        );

        return MessageAttachment::firstOrFail();
    }

    public function test_the_sender_can_open_their_own_attachment(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $this->actingAs($customer, 'sanctum')
            ->get("/api/messages/attachments/{$attachment->id}")
            ->assertOk();
    }

    public function test_the_recipient_can_open_it(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $this->actingAs($admin, 'sanctum')
            ->get("/api/messages/attachments/{$attachment->id}")
            ->assertOk();
    }

    /** The store inbox is shared - whichever admin is about answers it. */
    public function test_another_admin_can_open_it(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $this->actingAs($this->admin('second@example.test'), 'sanctum')
            ->get("/api/messages/attachments/{$attachment->id}")
            ->assertOk();
    }

    /**
     * 404 and not 403, deliberately. The ids are sequential, so a 403 on a row
     * that exists against a 404 on one that does not is a difference anyone
     * can measure by counting - and the existence of attachment 812 is itself
     * something we never agreed to tell them.
     */
    public function test_an_unrelated_customer_gets_a_404_not_a_403(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $this->actingAs($this->customer('stranger@example.test'), 'sanctum')
            ->get("/api/messages/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_a_guest_cannot_open_an_attachment(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $this->getJson("/api/messages/attachments/{$attachment->id}")
            ->assertUnauthorized();
    }

    /**
     * The panel route is a second door, not a second policy. It exists so the
     * Filament page inherits the panel's session middleware and the panel's
     * own login redirect.
     *
     * A customer is stopped at the panel door with a 403 rather than reaching
     * the controller's 404 - canAccessPanel() refuses them before routing gets
     * that far. That is a stricter answer, not a looser one, and it is the
     * same 403 every other admin URL gives them.
     */
    public function test_the_panel_route_applies_the_same_rule(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $url = "/admin/messages/attachments/{$attachment->id}";

        // Guest first: actingAs() persists for the rest of the test, so a
        // logged-out assertion made after one is not testing a logged-out
        // request at all.
        $this->get($url)->assertRedirect();

        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($this->customer('stranger@example.test'))->get($url)->assertForbidden();
    }

    // ── Failure and cleanup ────────────────────────────────────────────────

    /**
     * Files are written before the rows, so a failed transaction is the one
     * orphan case application code CAN close. It closes it.
     */
    public function test_a_failed_send_leaves_no_files_behind(): void
    {
        $customer = $this->customer();
        $disk = Storage::disk(config('filesystems.default'));

        try {
            MessageAttachmentService::send(
                senderId: $customer->id,
                receiverId: 999999,          // no such user - the insert fails
                content: 'Here you go',
                files: [$this->photo(), $this->photo('second.jpg')],
            );

            $this->fail('The send should not have succeeded.');
        } catch (\Throwable $e) {
            // Expected.
        }

        $this->assertSame(0, Message::count());
        $this->assertSame([], $disk->allFiles('messages'), 'Stored files should have been taken back down.');
    }

    /**
     * The gap the sweeper exists for. `message_attachments` cascades from
     * `messages`, which cascades from `users` - and a DATABASE cascade fires
     * no Eloquent events, so the model hook that deletes files never runs.
     * This test documents that the files really do survive, rather than
     * pretending the events cover it.
     */
    public function test_a_cascaded_delete_strands_the_files_and_the_sweeper_removes_them(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $disk = Storage::disk(config('filesystems.default'));

        $this->attachmentFrom($customer, $admin);
        $this->assertCount(1, $disk->allFiles('messages'));

        $customer->delete();

        $this->assertSame(0, MessageAttachment::count(), 'The rows cascade away.');
        $this->assertCount(1, $disk->allFiles('messages'), 'The files do not — this is the gap.');

        // Age the file past the cutoff, then sweep.
        $this->artisan('messages:prune-orphan-attachments', ['--hours' => 1, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertCount(1, $disk->allFiles('messages'), 'A dry run deletes nothing.');

        $this->travel(2)->hours();
        $this->artisan('messages:prune-orphan-attachments', ['--hours' => 1])->assertSuccessful();

        $this->assertSame([], $disk->allFiles('messages'));
    }

    /** A file a row still points at is not an orphan, however old it is. */
    public function test_the_sweeper_keeps_referenced_files(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $disk = Storage::disk(config('filesystems.default'));

        $this->attachmentFrom($customer, $admin);

        $this->travel(30)->days();
        $this->artisan('messages:prune-orphan-attachments', ['--hours' => 1])->assertSuccessful();

        $this->assertCount(1, $disk->allFiles('messages'));
    }

    /**
     * An orphan younger than the cutoff may belong to a send still in flight —
     * the files go down before the rows, so there is always a window in which
     * an orphan is not yet an orphan.
     */
    public function test_the_sweeper_spares_recent_files(): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('messages/01ABC/just-written.jpg', 'x');

        $this->artisan('messages:prune-orphan-attachments', ['--hours' => 24])->assertSuccessful();

        $this->assertCount(1, $disk->allFiles('messages'));
    }

    // ── Everything else that reads a message ───────────────────────────────

    /**
     * `content` is nullable now, so every surface that prints a message as one
     * line has a row that renders blank unless it goes through preview().
     */
    public function test_a_photo_only_message_still_previews_in_the_conversation_list(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'attachments' => [$this->photo(), $this->photo('second.jpg')],
        ])->assertCreated();

        $conversations = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/messages/conversations')
            ->assertOk()
            ->json();

        $this->assertSame('📎 2 photos', $conversations[0]['last_message']);
        $this->assertSame(1, $conversations[0]['unread_count']);
    }

    /**
     * Attachments ride on an existing message row, so the unread predicate —
     * which BadgeController mirrors exactly — must not have shifted.
     */
    public function test_a_photo_message_counts_once_towards_the_unread_badge(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $customer->id,
            'attachments' => [$this->photo(), $this->photo('b.jpg'), $this->photo('c.jpg')],
        ])->assertCreated();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/badges')
            ->assertOk()
            ->assertJsonPath('unread_messages', 1);
    }

    /** Chat is the default, and nothing backfills history into something else. */
    public function test_a_typed_message_is_kind_chat(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($customer, 'sanctum')->postJson('/api/messages/send', [
            'receiver_id' => $admin->id,
            'content'     => 'Do you deliver to Balanga?',
        ])->assertCreated();

        $this->assertSame(Message::KIND_CHAT, Message::first()->kind);
    }

    public function test_the_attachment_url_is_the_app_route_never_a_storage_path(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $attachment = $this->attachmentFrom($customer, $admin);

        $json = $attachment->fresh()->toArray();

        $this->assertStringContainsString("/api/messages/attachments/{$attachment->id}", $json['url']);
        $this->assertArrayNotHasKey('path', $json, 'The storage layout is not a client concern.');
        $this->assertArrayNotHasKey('disk', $json);
    }
}
