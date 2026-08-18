<?php
declare(strict_types=1);

/**
 * Named domain service facades for standalone-capable architecture.
 * Thin wrappers over existing ep_* functions — pages should call these or ep_* equivalently.
 */

final class EnterpriseMembershipService
{
    public static function getForUser(mysqli $db, int $userId, ?string $studentId = null): ?array
    {
        return ep_get_membership_for_user($db, $userId, $studentId);
    }
}

final class EnterpriseConsentService
{
    public static function farmerMatchConsent(mysqli $db, int $matchId, string $decision, string $channel): array
    {
        return ep_record_farmer_match_consent($db, $matchId, $decision, $channel);
    }
}

final class EnterprisePriceService
{
    public static function createDraft(mysqli $db, array $data): array
    {
        return ep_create_commodity_price_draft($db, $data);
    }

    public static function publish(mysqli $db, int $priceId, bool $secondApproval = false): array
    {
        return ep_publish_commodity_price($db, $priceId, $secondApproval);
    }

    public static function current(mysqli $db, array $filters = []): array
    {
        return ep_current_commodity_prices($db, $filters);
    }
}

/** Alias for agriculture-specific naming in the product brief. */
final class CommodityPriceService
{
    public static function createDraft(mysqli $db, array $data): array
    {
        return EnterprisePriceService::createDraft($db, $data);
    }

    public static function publish(mysqli $db, int $priceId, bool $secondApproval = false): array
    {
        return EnterprisePriceService::publish($db, $priceId, $secondApproval);
    }

    public static function current(mysqli $db, array $filters = []): array
    {
        return EnterprisePriceService::current($db, $filters);
    }
}

final class FarmerProfileService
{
    public static function register(mysqli $db, array $data, ?int $actingUserId = null): array
    {
        return ep_register_farmer($db, $data, $actingUserId);
    }
}

final class ProduceListingService
{
    public static function create(mysqli $db, array $data): array
    {
        return ep_create_produce_listing($db, $data);
    }

    public static function expireDue(mysqli $db): int
    {
        return ep_expire_produce_listings($db);
    }
}

final class BuyerCropDemandService
{
    public static function create(mysqli $db, array $data): array
    {
        return ep_create_buyer_crop_demand($db, $data);
    }
}

final class AgricultureMatchingService
{
    public static function suggest(mysqli $db, int $demandId): array
    {
        return ep_suggest_produce_matches($db, $demandId);
    }

    public static function record(mysqli $db, int $demandId, int $listingId, float $qty, string $unit, string $explanation = '', ?float $score = null): array
    {
        return ep_record_produce_match($db, $demandId, $listingId, $qty, $unit, $explanation, $score);
    }
}

final class EnterpriseMatchingService
{
    public static function suggest(mysqli $db, int $demandId): array
    {
        return AgricultureMatchingService::suggest($db, $demandId);
    }

    public static function record(mysqli $db, int $demandId, int $listingId, float $qty, string $unit, string $explanation = '', ?float $score = null): array
    {
        return AgricultureMatchingService::record($db, $demandId, $listingId, $qty, $unit, $explanation, $score);
    }
}

final class SmsGatewayService
{
    public static function send(mysqli $db, string $phone, string $body, string $type = 'general', ?string $idempotencyKey = null): array
    {
        return ep_adapters()['sms']->send($db, $phone, $body, $type, $idempotencyKey);
    }
}

final class UssdGatewayService
{
    public static function start(mysqli $db, string $phone, ?string $providerRequestId = null): array
    {
        return ep_ussd_start_session($db, $phone, $providerRequestId);
    }

    public static function handle(mysqli $db, string $sessionRef, string $input): array
    {
        return ep_ussd_handle_input($db, $sessionRef, $input);
    }
}

final class EnterpriseCommunicationService
{
    public static function normalizeMsisdn(string $raw): string
    {
        return ep_normalize_msisdn($raw);
    }
}

final class EnterpriseNotificationService
{
    public static function notify(mysqli $db, string $userId, string $role, string $title, string $message, string $url = ''): void
    {
        ep_adapters()['notify']->notify($db, $userId, $role, $title, $message, $url);
    }
}

final class EnterpriseOutcomeService
{
    // Reuses existing ep_outcome helpers; agriculture outcomes use match outcome_status separately.
}

final class EnterpriseReferralService
{
    /** Referral sharing only after farmer consent accepted. */
    public static function canShareFarmerContact(array $match): bool
    {
        return ($match['farmer_consent_status'] ?? '') === 'accepted'
            && ($match['officer_decision'] ?? '') === 'approved';
    }
}

final class EnterprisePartnerService
{
    /** Thin stub until institution-led partners migrate; crop buyers use demand verification today. */
    public static function buyerIsVerified(string $status): bool
    {
        return $status === 'verified';
    }
}

final class EnterpriseOpportunityService
{
    public static function types(): array
    {
        return ep_opportunity_types();
    }
}

final class EnterpriseProfileService
{
    public static function byMembership(mysqli $db, int $membershipId): ?array
    {
        return function_exists('ep_get_profile_by_membership')
            ? ep_get_profile_by_membership($db, $membershipId)
            : null;
    }
}
