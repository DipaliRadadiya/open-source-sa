<?php

return [

    /*
    | Deploy-on-push setup, one entry per provider. Returned by
    | GET /api/webhook-providers so the frontend renders the steps from data —
    | the three providers genuinely differ, and the differences are the part
    | users get wrong.
    */

    'instructions' => [
        'github' => 'In your repository, open Settings → Webhooks → Add webhook. Paste the URL below, set Content type to application/json, paste the secret into Secret, and select "Just the push event".',
        'gitlab' => 'In your project, open Settings → Webhooks → Add new webhook. Paste the URL below and select the "Push events" trigger. Then either select "Generate signing token" in GitLab and paste that token here (recommended), or paste this panel\'s secret into GitLab\'s "Secret token" field.',
        'bitbucket' => 'In your repository, open Repository settings → Webhooks → Add webhook. Paste the URL below, paste the secret into Secret, and select the "Repository push" trigger.',
    ],

];
