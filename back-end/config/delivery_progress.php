<?php

return [
    // Operational reminders only: these never infer physical delivery or change an order.
    // Set a stage to 0 to disable its reminder. No external messages are sent.
    'minutes' => [
        'assigned' => (int) env('DELIVERY_ASSIGNED_REMINDER_MINUTES', 10),
        'picked_up' => (int) env('DELIVERY_PICKED_UP_REMINDER_MINUTES', 5),
        'on_the_way' => (int) env('DELIVERY_ON_WAY_REMINDER_MINUTES', 45),
    ],
    // Total minutes since actual progress, never earlier than the driver reminder.
    'escalation_minutes' => [
        'assigned' => (int) env('DELIVERY_ASSIGNED_ESCALATION_MINUTES', 20),
        'picked_up' => (int) env('DELIVERY_PICKED_UP_ESCALATION_MINUTES', 10),
        'on_the_way' => (int) env('DELIVERY_ON_WAY_ESCALATION_MINUTES', 60),
    ],
];
