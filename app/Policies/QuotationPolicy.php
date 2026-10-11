<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Who may see, make and settle a price offer (spec §9).
 *
 * The two sides of a quotation want opposite things from it, so most methods
 * here are written twice: once for the customer who owns the job, once for the
 * professional who priced it. Neither may act on the other's behalf, and an
 * administrator sits outside both — able to read everything, but not to accept a
 * price on a customer's behalf.
 *
 * These checks are the pre-flight. The decisive ones run inside
 * QuotationService::accept(), under a row lock, because two tabs can both pass a
 * policy check before either has written anything.
 */
class QuotationPolicy
{
    /**
     * The comparison table on a job the caller posted.
     *
     * An administrator reading a disputed job is included; a professional is
     * not, because their own offers are listed through `viewOwn()` instead and a
     * trade should not read its competitors' pricing.
     */
    public function viewAny(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->id === $serviceRequest->customer_id || $user->isAdmin();
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->ownsRequest($user, $quotation)
            || $this->isAuthor($user, $quotation)
            || $user->isAdmin();
    }

    /**
     * A professional's own offers, across every job they have bid on.
     */
    public function viewOwn(User $user): bool
    {
        return $user->isProfessional();
    }

    /**
     * Submitting an offer on an open job.
     *
     * Four things must hold, and each closes a different hole. The permission
     * makes the ability revocable without suspending an account. The verified
     * profile keeps the platform's promise — an unvetted tradesperson must not be
     * able to price work. The request must still be open, or a professional could
     * bid on a job that was awarded last week. And they must not be the customer,
     * which would let somebody quote on their own posting.
     *
     * Quoting twice on the same job is prevented structurally by the unique index
     * on `(service_request_id, professional_id)`, not by a check here that a race
     * could slip past.
     */
    public function create(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isProfessional()
            && $user->hasPermissionTo(Permission::CreateQuotation->value)
            && ($user->professionalProfile?->isVerified() ?? false)
            && $serviceRequest->acceptsQuotations()
            && $user->id !== $serviceRequest->customer_id;
    }

    /**
     * Revising an offer that is still live.
     */
    public function update(User $user, Quotation $quotation): bool
    {
        return $this->isAuthor($user, $quotation) && $quotation->isEditable();
    }

    /**
     * Pulling an offer. Narrower than updating: a withdrawn quotation stays
     * withdrawn, so this is refused the moment it is no longer live.
     */
    public function withdraw(User $user, Quotation $quotation): bool
    {
        return $this->isAuthor($user, $quotation) && $quotation->isLive();
    }

    /**
     * Taking an offer.
     *
     * Ownership only. Whether the offer can still be taken is deliberately not
     * answered here, even though the model could: the decisive check has to
     * happen against the *locked* row, because two tabs accepting two different
     * offers on the same job can both pass a check made before either writes.
     * Leaving it to QuotationService means there is one answer to "this offer
     * cannot be accepted", with one message explaining which of the three reasons
     * applied, rather than a second silent refusal here that says only 403.
     */
    public function accept(User $user, Quotation $quotation): bool
    {
        return $this->ownsRequest($user, $quotation);
    }

    /**
     * Turning one offer down while leaving the job open to the others.
     */
    public function reject(User $user, Quotation $quotation): bool
    {
        return $this->ownsRequest($user, $quotation) && $quotation->isLive();
    }

    private function ownsRequest(User $user, Quotation $quotation): bool
    {
        return $user->id === $quotation->serviceRequest->customer_id;
    }

    private function isAuthor(User $user, Quotation $quotation): bool
    {
        return $user->id === $quotation->professional_id;
    }
}
