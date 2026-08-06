<?php

namespace App\Enums;

/**
 * What started a deploy.
 *
 * Worth recording rather than inferring from whether an actor is present: a
 * redeploy is also manual and also has an actor, but it is answering a
 * different question — "we ran this again" rather than "someone pushed a
 * button after a change".
 */
enum DeploymentTrigger: string
{
    /** Someone pressed Deploy. */
    case Manual = 'manual';

    /** A push arrived at the webhook. No actor. */
    case Webhook = 'webhook';

    /** Someone re-ran an earlier deploy. */
    case Redeploy = 'redeploy';

    /**
     * The first fetch, run automatically once the site was provisioned.
     *
     * Recorded separately rather than as `manual`: nobody pressed anything,
     * and a deploy history that claims they did is the kind of small lie that
     * makes the rest of the history untrustworthy.
     */
    case Initial = 'initial';

    public function label(): string
    {
        return __('deployment.trigger.'.$this->value);
    }
}
