<?php

// The built-in action log (Martis\Resources\ActionEventResource) and the
// "Action Events" panel of an Actionable model's detail page. Labels follow
// Nova 5's ActionResource ("Action Name" => "Name", ...).
return [
    'label' => 'Action Events',
    'singular_label' => 'Action Event',
    'subtitle' => 'Audit log of all actions executed in the admin panel',
    'id' => 'ID',
    'name' => 'Name',
    'initiated_by' => 'Initiated By',
    'target' => 'Target',
    'status' => 'Status',
    'original' => 'Original',
    'changes' => 'Changes',
    'exception' => 'Exception',
    'happened_at' => 'Happened At',
    'status_waiting' => 'Waiting',
    'status_running' => 'Running',
    'status_finished' => 'Finished',
    'status_failed' => 'Failed',
    'status_denied' => 'Denied',
];
