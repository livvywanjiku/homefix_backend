<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Who may see and change a job posting (spec §8).
 *
 * Guests are not represented here at all: every route that reaches this policy
 * is already behind `auth:sanctum`. What this class decides is *which* signed-in
 * account may act — a distinction the route middleware alone cannot make, since
 * it only knows the role, not who owns the row.
 */
class ServiceRequestPolicy
{
    /**
     * The customer's own list. The controller scopes the query to the account,
     * so this only confirms the account is a customer at all.
     */
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isAdmin();
    }

    /**
     * A single request.
     *
     * Three accounts may read it: the customer who posted it, an administrator
     * handling a dispute, and any verified professional — because a posting is
     * an advertisement, and the board it lives on is open to the trade. What is
     * *not* public is the address and the access notes; see
     * `viewContactDetails()`.
     */
    public function view(User $user, ServiceRequest $serviceRequest): bool
    {
        if ($this->owns($user, $serviceRequest) || $user->isAdmin()) {
            return true;
        }

        return $user->isProfessional()
            && $serviceRequest->status->isListed()
            && ! $serviceRequest->hasExpired();
    }

    /**
     * Posting a job. Gated on the permission rather than the role alone, so the
     * capability can be withdrawn from an account without suspending it.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::CreateServiceRequest->value);
    }

    /**
     * Editing the brief. Once a professional has quoted, the description is
     * frozen — see `ServiceRequestStatus::isEditable()` for why.
     */
    public function update(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->owns($user, $serviceRequest) && $serviceRequest->isEditable();
    }

    public function cancel(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->owns($user, $serviceRequest)
            && $serviceRequest->isCancellable()
            && $user->hasPermissionTo(Permission::CancelServiceRequest->value);
    }

    /**
     * Adding or removing the photos on a request.
     *
     * Tied to the same window as editing: a photo added after quoting changes
     * the scope the professional priced.
     */
    public function manageImages(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->owns($user, $serviceRequest) && $serviceRequest->isEditable();
    }

    /**
     * Whether the street address and access notes may be shown.
     *
     * Kept narrow on purpose. A professional browsing the open board sees the
     * town and the job, not where the customer lives — the address is released
     * only to a professional who has actually quoted on this request, which is
     * the point at which they are expected to turn up. Until the quotation flow
     * exists that means the owner and administrators only.
     */
    public function viewContactDetails(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->owns($user, $serviceRequest) || $user->isAdmin();
    }

    /**
     * The board a professional works from. Any professional may browse it; the
     * controller narrows it to their services and service areas.
     */
    public function viewBoard(User $user): bool
    {
        return $user->hasRole(UserRole::Professional->value);
    }

    private function owns(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->id === $serviceRequest->customer_id;
    }
}
