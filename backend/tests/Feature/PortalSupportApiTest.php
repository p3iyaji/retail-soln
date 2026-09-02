<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CustomerPortal\Models\PortalContactInquiry;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Tests\TestCase;

class PortalSupportApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Bag & Cartons Ltd',
        ]);

        config([
            'customer_portal.organization_id' => $this->organization->id,
            'customer_portal.support_email' => 'support@bagcartons.test',
            'customer_portal.support_phone' => '+234 800 000 0000',
            'customer_portal.business_hours' => 'Mon–Sat 9:00–18:00',
        ]);

        Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'address_line1' => '12 Market Road',
            'city' => 'Lagos',
            'country' => 'NG',
            'is_active' => true,
        ]);
    }

    public function test_support_info_is_public(): void
    {
        $response = $this->getJson('/api/v1/portal/support/info');

        $response->assertOk()
            ->assertJsonPath('data.organizationName', 'Bag & Cartons Ltd')
            ->assertJsonPath('data.supportEmail', 'support@bagcartons.test')
            ->assertJsonPath('data.supportPhone', '+234 800 000 0000')
            ->assertJsonPath('data.businessHours', 'Mon–Sat 9:00–18:00')
            ->assertJsonCount(1, 'data.stores');
    }

    public function test_contact_form_creates_inquiry(): void
    {
        $response = $this->postJson('/api/v1/portal/contact', [
            'name' => 'Jane Shopper',
            'email' => 'jane@example.com',
            'phone' => '+234 801 234 5678',
            'subject' => 'Product question',
            'message' => 'Do you stock bulk cartons?',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.message', 'Thanks for reaching out. Our team will get back to you soon.');

        $this->assertDatabaseHas('portal_contact_inquiries', [
            'organization_id' => $this->organization->id,
            'email' => 'jane@example.com',
            'subject' => 'Product question',
            'status' => 'new',
        ]);
    }

    public function test_contact_form_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/portal/contact', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'subject', 'message']);
    }

    public function test_chat_returns_greeting(): void
    {
        $response = $this->postJson('/api/v1/portal/chat', [
            'message' => 'Hello',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['reply', 'suggestions', 'productMatches'],
            ]);

        $this->assertStringContainsString('Hi!', $response->json('data.reply'));
    }

    public function test_chat_suggests_contact_for_human_help(): void
    {
        $response = $this->postJson('/api/v1/portal/chat', [
            'message' => 'I need to speak to someone',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Contact', $response->json('data.reply'));
    }

    public function test_contact_inquiry_model_persists(): void
    {
        $inquiry = PortalContactInquiry::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'subject' => 'Help',
            'message' => 'Need assistance',
            'status' => 'new',
        ]);

        $this->assertNotNull($inquiry->id);
    }
}
