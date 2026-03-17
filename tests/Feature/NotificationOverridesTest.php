<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Contracts\LongWaitDetectedNotification as LongWaitDetectedNotificationContract;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Notifications\LongWaitDetected as LongWaitDetectedNotification;
use Laravel\Horizon\Tests\IntegrationTest;

class NotificationOverridesTest extends IntegrationTest
{
    public function test_custom_notifications_are_sent_if_specified()
    {
        Notification::fake();

        Horizon::routeMailNotificationsTo('taylor@laravel.com');

        Container::getInstance()->bind(LongWaitDetectedNotificationContract::class, CustomLongWaitDetectedNotification::class);

        event(new LongWaitDetected('redis', 'test-queue-2', 60));

        Notification::assertSentOnDemand(CustomLongWaitDetectedNotification::class);
    }

    public function test_normal_notifications_are_sent_if_not_specified()
    {
        Notification::fake();

        Horizon::routeMailNotificationsTo('taylor@laravel.com');

        event(new LongWaitDetected('redis', 'test-queue-2', 60));

        Notification::assertSentOnDemand(LongWaitDetectedNotification::class);
    }
}

class CustomLongWaitDetectedNotification extends LongWaitDetectedNotification implements LongWaitDetectedNotificationContract
{
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->line('This is a custom notification for a long wait.');
    }
}
