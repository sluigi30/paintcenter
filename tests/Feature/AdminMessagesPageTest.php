<?php

namespace Tests\Feature;

use App\Filament\Pages\Messages as MessagesPage;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageAttachmentService;
use App\Services\OrderMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin half of the thread.
 *
 * The page had no render coverage at all before this — it is a hand-written
 * Livewire page rather than a Filament resource, so nothing in the
 * screens-render smoke test reached it.
 */
class AdminMessagesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_the_messages_page_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/messages')->assertOk();
    }

    public function test_an_admin_can_reply_with_a_photo(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->call('selectUser', $customer->id)
            ->set('photos', [UploadedFile::fake()->image('swatch.jpg', 900, 600)])
            ->set('newMessage', 'This is the shade you asked about.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = Message::firstOrFail();

        $this->assertSame($admin->id, $message->sender_id);
        $this->assertSame($customer->id, $message->receiver_id);
        $this->assertCount(1, $message->attachments);
        $this->assertTrue($message->attachments->first()->exists());
    }

    public function test_an_admin_can_send_a_photo_with_no_words(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->call('selectUser', $customer->id)
            ->set('photos', [UploadedFile::fake()->image('a.jpg')])
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertNull(Message::firstOrFail()->content);
    }

    /** Nothing is sent for an empty composer — not even an empty row. */
    public function test_an_empty_composer_sends_nothing(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->call('selectUser', $customer->id)
            ->set('newMessage', '   ')
            ->call('sendMessage');

        $this->assertSame(0, Message::count());
    }

    /** A staged photo can be taken back out before sending. */
    public function test_a_staged_photo_can_be_removed(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->call('selectUser', $customer->id)
            ->set('photos', [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ])
            ->call('removePhoto', 0)
            ->assertCount('photos', 1);
    }

    public function test_a_format_nothing_can_display_is_rejected_on_staging(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->call('selectUser', $customer->id)
            ->set('photos', [UploadedFile::fake()->create('photo.heic', 300, 'image/heic')])
            ->assertHasErrors('photos')
            // and it is gone from the tray, not merely flagged there
            ->assertCount('photos', 0);
    }

    /**
     * The sidebar preview is the other surface that breaks on a photo-only
     * message — content is nullable now, so a row that prints `content`
     * directly renders blank.
     */
    public function test_the_sidebar_previews_a_photo_only_message(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        MessageAttachmentService::send(
            senderId: $customer->id,
            receiverId: $admin->id,
            content: null,
            files: [UploadedFile::fake()->image('wall.jpg')],
        );

        Livewire::actingAs($admin)
            ->test(MessagesPage::class)
            ->assertSee('📎 Photo', false);
    }

    /** The inbox is shared — one admin sees what a customer sent another. */
    public function test_the_inbox_is_shared_between_admins(): void
    {
        $first = $this->admin();
        $second = $this->admin('second@example.test');
        $customer = $this->customer();

        MessageAttachmentService::send($customer->id, $first->id, 'Do you deliver?');

        Livewire::actingAs($second)
            ->test(MessagesPage::class)
            ->assertSee('Do you deliver?')
            ->assertSee('Juan');
    }

    /**
     * An order update is posted through an admin account but was not typed by
     * anyone, so the thread draws it as a card rather than as that admin
     * speaking. Asserted on the stored kind — the rendering follows from it.
     */
    public function test_an_order_update_is_marked_as_a_system_message(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $order = $customer->orders()->create([
            'order_type'   => 'pickup',
            'status'       => 'pending',
            'order_date'   => now(),
            'total_amount' => 1400,
        ]);

        OrderMessageService::orderPlaced($order);

        $message = Message::firstOrFail();

        $this->assertSame(Message::KIND_ORDER_UPDATE, $message->kind);
        $this->assertTrue($message->isOrderUpdate());
    }

    /**
     * History is deliberately NOT backfilled: an order update written before
     * the kind column existed stays a chat bubble. Recognising it would mean
     * pattern-matching free text, and an admin who once typed something
     * similar would be miscategorised with no way to tell.
     */
    public function test_history_is_not_reclassified(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $legacy = Message::create([
            'sender_id'   => $admin->id,
            'receiver_id' => $customer->id,
            'content'     => 'Order received — ₱1,400.00',
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        $this->assertSame(Message::KIND_CHAT, $legacy->fresh()->kind);
        $this->assertFalse($legacy->fresh()->isOrderUpdate());
    }
}
