<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colour-from-image tool on the product form.
 *
 * It is a client-side canvas widget, so there is no server behaviour to assert.
 * What can break silently is the wiring: the Alpine module not being published
 * by filament:assets, or the tool writing into the wrong state path after a
 * field is renamed. Both show up in the rendered page, so that is what is
 * checked here.
 */
class ProductColorPickerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'admin@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ]);
    }

    public function test_the_create_product_form_renders_the_colour_picker_tool(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/products/create');

        $response->assertOk();
        $response->assertSee('Pick color from an image');
    }

    public function test_the_tool_targets_the_hex_code_field_of_its_own_variant_row(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/products/create');

        // Colour moved onto the variants (2026-09-14), so the picker now sits
        // inside the repeater and there is one per row. It hands its colour
        // back by writing the state path of the ColorPicker beside it, which
        // the blade derives from its own — the row key is a generated uuid, so
        // what is asserted is the shape: a variants row, ending in hex_code.
        $this->assertMatchesRegularExpression(
            "/colorFromImage\(\{ statePath: 'data\.variants\.[^.']+\.hex_code' \}\)/",
            $response->getContent(),
            'The colour picker is not wired to its own variant row\'s hex_code field.',
        );
    }

    public function test_the_alpine_module_has_been_published(): void
    {
        $this->assertFileExists(
            public_path('js/app/components/color-from-image.js'),
            'Run `php artisan filament:assets` — the colour picker module is not published.',
        );
    }
}
