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
            ->assertSee('Submitted applications go to HRMO verification before Office Head review')
            ->assertSee('Approval hierarchy:')
            ->assertSee('Other available HR services')
            ->assertSee('Employee Profile Management')
            ->assertSee('Attendance Monitoring')
            ->assertSee('Leave Credits Monitoring')
            ->assertSee('Document Management')
            ->assertSee('Reports and Analytics')
            ->assertSee('Account and Access Management')
            ->assertSee('Leave Application and Approval')
            ->assertSee('Leave Cancellation and Returned Applications')
            ->assertSee('Office Head Availability and Routing')
            ->assertSee('GPS Attendance Monitoring')
            ->assertSee('Monthly Leave Accrual')
            ->assertSee('Holiday and Workweek Configuration')
            ->assertSee('Notification Management')
            ->assertSee('Department and Position Management')
            ->assertSee('Attendance Correction')
            ->assertSee('Administrative Reporting')
            ->assertSee('Admin / HRMO')
            ->assertSee('Office Head')
            ->assertSee('Employee')
            ->assertSee('Mayor')
            ->assertSee('href="#roles"', false)
            ->assertSee('id="roles"', false)
            ->assertSee(route('login'), false)
            ->assertSee('Access HRIS Portal')
            ->assertSee('Ready to Access Municipal HR Services?');
    }
}
