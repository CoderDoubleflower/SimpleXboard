<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class TransactionalMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_and_mail_login_still_render_and_send_from_the_queue(): void
    {
        foreach (['verify' => ['code' => '123456'], 'mailLogin' => ['link' => 'https://panel.example.test/login?token=one-time']] as $template => $values) {
            $params = $this->params($template, $values);
            Mail::shouldReceive('send')->once()
                ->with('mail.default.' . $template, $params['template_value'], Mockery::type('Closure'))
                ->andReturnUsing(function ($view, $data, $callback) use ($params, $values) {
                    $message = new Message(new Email());
                    $callback($message);
                    $this->assertSame($params['email'], $message->getSymfonyMessage()->getTo()[0]->getAddress());
                    $this->assertSame($params['subject'], $message->getSymfonyMessage()->getSubject());
                    foreach ($values as $value) {
                        $this->assertStringContainsString($value, view($view, $data)->render());
                    }
                });
            (new SendEmailJob($params))->handle();
            $this->assertDatabaseHas('v2_mail_log', [
                'email' => $params['email'],
                'template_name' => 'mail.default.' . $template,
                'error' => null,
            ]);
        }
        $this->assertSame(2, MailLog::count());
    }

    public function test_old_campaign_and_reminder_jobs_are_discarded_without_sending(): void
    {
        Mail::shouldReceive('send')->never();
        Mail::shouldReceive('html')->never();
        foreach (['remindExpire', 'remindTraffic', 'notify'] as $template) {
            (new SendEmailJob($this->params($template)))->handle();
        }
        $this->assertSame(0, MailLog::count());
    }

    public function test_retired_stored_reminder_templates_cannot_reactivate_mail(): void
    {
        MailTemplate::create(['name' => 'remindExpire', 'subject' => 'Old reminder', 'content' => 'Renew now']);
        Mail::shouldReceive('send')->never();
        Mail::shouldReceive('html')->never();

        $this->expectException(\InvalidArgumentException::class);
        MailService::sendEmail($this->params('remindExpire'));
    }

    public function test_synchronous_smtp_test_notifications_remain_available(): void
    {
        $params = $this->params('notify', ['content' => 'SMTP configuration test']);
        Mail::shouldReceive('send')->once()
            ->with('mail.default.notify', $params['template_value'], Mockery::type('Closure'));

        $result = MailService::sendEmail($params);

        $this->assertNull($result['error']);
        $this->assertSame('mail.default.notify', $result['template_name']);
        $this->assertSame(1, MailLog::count());
    }

    private function params(string $template, array $values = []): array
    {
        return [
            'email' => 'member@example.test',
            'subject' => 'Account access',
            'template_name' => $template,
            'template_value' => $values + ['name' => 'SimpleXboard', 'url' => 'https://panel.example.test'],
        ];
    }
}
