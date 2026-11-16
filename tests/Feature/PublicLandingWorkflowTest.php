<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLandingWorkflowTest extends TestCase
{
    public function test_public_landing_page_explains_the_hris_workflow_and_links_to_login(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="#how-it-works"', false)
            ->assertSee('How It Works')
            ->assertSee('How the HRIS Works')
            ->assertSee('structured digital workflow')
            ->assertSee('Other available HR services')
            ->assertSee('Employee Profile Management')
            ->assertSee('Attendance Monitoring')
            ->assertSee('Leave Credits Monitoring')
            ->assertSee('Document Management')
            ->assertSee('Reports and Analytics')
            ->assertSee('Account and Access Management')
            ->assertSee(route('login'), false)
            ->assertSee('Ready to Access Municipal HR Services?');
    }
}
