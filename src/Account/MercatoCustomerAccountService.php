<?php
namespace ProcessWire;

final class MercatoCustomerAccountService extends Wire {
    private const AUTH_SESSION_KEY = 'mrc_account_auth_attempts';
    private const LOG_NAME = 'mercato-account-security';

    public function __construct(private Mercato $commerce) { parent::__construct(); }

    public function isEnabled(): bool { return MercatoAccountPolicy::normalizeMode($this->commerce->customer_accounts_mode ?? 'disabled') !== 'disabled'; }
    public function isRequiredAtCheckout(): bool { return MercatoAccountPolicy::normalizeMode($this->commerce->customer_accounts_mode ?? 'disabled') === 'required_verified'; }
    public function isVerified(?User $user = null): bool { $user ??= $this->wire('user'); return $user->id > 0 && !$user->isGuest() && (int) ($user->mrc_customer_verified ?? 0) === 1; }

    public function register(string $email, string $password, array $profile = []): array {
        if (!$this->isEnabled()) throw new WireException($this->commerce->_('Customer accounts are disabled.'), 403);
        $email = (string) $this->wire('sanitizer')->email(MercatoAccountPolicy::normalizeEmail($email));
        if ($email === '' || strlen($password) < 10) throw new WireException($this->commerce->_('Enter a valid email and a password of at least 10 characters.'), 422);
        if ($this->findByEmail($email)) return ['ok' => true, 'message' => MercatoAccountPolicy::GENERIC_AUTH_MESSAGE];
        $role = $this->wire('roles')->get('mercato-customer');
        if (!$role || !$role->id) throw new WireException($this->commerce->_('Customer account role is not installed.'), 503);
        $profile = $this->commerce->customerAccountRegistrationData($profile, $email);
        $user = new User(); $user->of(false); $user->name = $this->uniqueName($email); $user->email = $email; $user->pass = $password; $user->addRole($role);
        $this->applyProfile($user, $profile, false); $this->wire('users')->save($user);
        $token = $this->issueToken($user, 'verify');
        $this->sendSecurityEmail($user, $this->verificationUrl($user, $token), 'Verify your email address to activate your customer account.', 'account_created');
        $this->record('account_registered', $user, ['email_hash' => hash('sha256', $email)]); $this->commerce->analyticsService()->track('account_registered', ['account_id' => $this->analyticsAccountId($user)]); $this->commerce->customerAccountCreated($user);
        return ['ok' => true, 'message' => MercatoAccountPolicy::GENERIC_AUTH_MESSAGE];
    }

    public function verify(int $userId, string $token): bool {
        $user = $this->wire('users')->get($userId); if (!$this->validToken($user, 'verify', $token)) return false;
        $user->of(false); $user->mrc_customer_verified = 1; $this->clearToken($user, 'verify'); $this->wire('users')->save($user);
        $this->record('account_verified', $user); $this->commerce->analyticsService()->track('account_verified', ['account_id' => $this->analyticsAccountId($user)]); $this->commerce->customerAccountVerified($user); return true;
    }

    public function login(string $email, string $password, string $ip = ''): array {
        if (!$this->isEnabled()) return ['ok' => false, 'message' => $this->commerce->_('Customer accounts are disabled.')];
        $email = (string) $this->wire('sanitizer')->email(MercatoAccountPolicy::normalizeEmail($email)); $key = hash('sha256', $email . '|' . $ip);
        if ($this->isRateLimited($key)) return ['ok' => false, 'message' => $this->commerce->_('Sign-in is temporarily unavailable. Try again later.')];
        $candidate = $this->findByEmail($email); $authenticated = $candidate && $this->wire('session')->login((string) $candidate->name, $password);
        if (!$authenticated) { $this->recordAttempt($key); return ['ok' => false, 'message' => $this->commerce->_('Email or password is incorrect.')]; }
        if (!$this->isVerified($candidate)) { $this->wire('session')->logout(); $this->recordAttempt($key); return ['ok' => false, 'message' => $this->commerce->_('Email or password is incorrect.')]; }
        // ProcessWire's Session::login() regenerates the session ID before it
        // installs the authenticated user, preventing fixation.
        $this->clearAttempts($key); $this->record('account_login', $candidate); $this->commerce->analyticsService()->track('account_login', ['account_id' => $this->analyticsAccountId($candidate)]);
        return ['ok' => true, 'message' => $this->commerce->_('Signed in.')];
    }

    public function logout(): void { if (!$this->wire('user')->isGuest()) $this->record('account_logout', $this->wire('user')); $this->wire('session')->logout(); }

    public function requestPasswordReset(string $email): array {
        $email = (string) $this->wire('sanitizer')->email(MercatoAccountPolicy::normalizeEmail($email)); $user = $this->findByEmail($email);
        if ($this->isEnabled() && $user && $this->isVerified($user)) { $token = $this->issueToken($user, 'reset'); $this->sendSecurityEmail($user, $this->resetUrl($user, $token), 'Use the secure account link to choose a new password.'); $this->record('password_reset_requested', $user); }
        return ['ok' => true, 'message' => MercatoAccountPolicy::GENERIC_AUTH_MESSAGE];
    }

    public function resetPassword(int $userId, string $token, string $password): bool {
        $user = $this->wire('users')->get($userId); if (strlen($password) < 10 || !$this->validToken($user, 'reset', $token)) return false;
        $user->of(false); $user->pass = $password; $this->clearToken($user, 'reset'); $this->wire('users')->save($user); $this->wire('session')->logout(); $this->record('password_reset_completed', $user); return true;
    }

    public function profile(User $user): array {
        $this->assertCustomer($user); $addresses = json_decode((string) ($user->mrc_customer_addresses ?? ''), true); $preferences = json_decode((string) ($user->mrc_customer_preferences ?? ''), true);
        return ['email' => (string) $user->email, 'first_name' => (string) ($user->mrc_first_name ?? ''), 'last_name' => (string) ($user->mrc_last_name ?? ''), 'phone' => (string) ($user->mrc_phone ?? ''), 'addresses' => is_array($addresses) ? $addresses : [], 'preferences' => is_array($preferences) ? $preferences : [], 'revision' => (int) ($user->mrc_customer_revision ?? 0), 'verified' => $this->isVerified($user)];
    }

    public function updateProfile(User $user, array $profile, int $expectedRevision): array {
        $this->assertCustomer($user); if ((int) ($user->mrc_customer_revision ?? 0) !== $expectedRevision) throw new WireException($this->commerce->_('Your profile changed in another session. Reload and try again.'), 409);
        $user->of(false); $this->applyProfile($user, $profile, true); $user->mrc_customer_revision = $expectedRevision + 1; $this->wire('users')->save($user); $this->record('profile_updated', $user); $this->commerce->analyticsService()->track('account_profile_updated', ['account_id' => $this->analyticsAccountId($user)]); $this->commerce->customerAccountProfileUpdated($user, $profile); return $this->profile($user);
    }

    public function findOrders(User $user, int $pageNum = 1, ?int $limit = null): PageArray {
        $this->assertCustomer($user); $limit = max(1, min(100, $limit ?? (int) ($this->commerce->account_orders_per_page ?? 10))); $start = max(0, $pageNum - 1) * $limit; $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        return $this->wire('pages')->find("template=$template, include=all, mrc_customer_user_id=" . (int) $user->id . ", sort=-created, start=$start, limit=$limit");
    }

    public function ownsOrder(User $user, Page $order): bool { return $order->template->name === (string) $this->commerce->order_template && MercatoAccountPolicy::ownsRecord((int) ($order->mrc_customer_user_id ?? 0), (int) $user->id); }
    public function ownsQuote(User $user, Page $quote): bool { return $quote->template->name === (string) $this->commerce->quote_template && MercatoAccountPolicy::ownsRecord((int) ($quote->mrc_quote_customer_user_id ?? 0), (int) $user->id); }
    public function assertOwnsOrder(User $user, Page $order): void { if (!$this->ownsOrder($user, $order)) throw new WireException($this->commerce->_('Order not found.'), 404); }

    public function requestGuestOrderClaim(User $user, Page $order): array {
        $this->assertCustomer($user); if (empty($this->commerce->account_claim_guest_orders) || !MercatoAccountPolicy::canClaim((string) $order->mrc_email, (string) $user->email, (int) ($order->mrc_customer_user_id ?? 0))) return ['ok' => false, 'message' => $this->commerce->_('Order is not eligible for account claim.')];
        $token = bin2hex(random_bytes(32)); $details = ['token_hash' => hash('sha256', $token), 'expires' => time() + max(5, (int) ($this->commerce->account_token_ttl_minutes ?? 60)) * 60, 'user_id' => (int) $user->id];
        $order->of(false); $order->mrc_customer_claim_details = json_encode($details); $this->wire('pages')->save($order); $url = $this->accountUrl(['action' => 'claim', 'order' => (int) $order->id, 'token' => $token]); $this->sendSecurityEmail($user, $url, 'Confirm this secure link to attach the guest order to your account.'); $this->record('guest_order_claim_requested', $user, ['order_id' => (int) $order->id]); return ['ok' => true, 'message' => MercatoAccountPolicy::GENERIC_AUTH_MESSAGE];
    }

    public function claimGuestOrder(User $user, Page $order, string $token): bool {
        $this->assertCustomer($user); $details = json_decode((string) ($order->mrc_customer_claim_details ?? ''), true); if (!is_array($details) || MercatoAccountPolicy::tokenExpired((int) ($details['expires'] ?? 0)) || (int) ($details['user_id'] ?? 0) !== (int) $user->id || !hash_equals((string) ($details['token_hash'] ?? ''), hash('sha256', $token)) || !MercatoAccountPolicy::canClaim((string) $order->mrc_email, (string) $user->email, (int) ($order->mrc_customer_user_id ?? 0))) return false;
        $order->of(false); $order->mrc_customer_user_id = (int) $user->id; $order->mrc_customer_claim_details = ''; $this->wire('pages')->save($order); $this->record('guest_order_claimed', $user, ['order_id' => (int) $order->id]); $this->commerce->analyticsService()->track('guest_order_claimed', ['account_id' => $this->analyticsAccountId($user), 'record_id' => (int) $order->id]); $this->commerce->customerAccountClaimed($user, $order); return true;
    }

    public function accountUrl(array $query = []): string { return rtrim($this->commerce->getHttpRoot(), '/') . '/account/' . ($query ? '?' . http_build_query($query) : ''); }
    public function verificationUrl(User $user, string $token): string { return $this->accountUrl(['action' => 'verify', 'user' => (int) $user->id, 'token' => $token]); }
    public function resetUrl(User $user, string $token): string { return $this->accountUrl(['action' => 'reset', 'user' => (int) $user->id, 'token' => $token]); }

    private function findByEmail(string $email): ?User { if ($email === '') return null; $safe = $this->wire('sanitizer')->selectorValue($email); $user = $this->wire('users')->get("email=$safe, roles=mercato-customer, include=all"); return $user && $user->id ? $user : null; }
    private function uniqueName(string $email): string { $base = 'customer-' . substr(hash('sha256', $email), 0, 20); $name = $base; $i = 1; while ($this->wire('users')->get("name=" . $this->wire('sanitizer')->selectorValue($name))->id) $name = $base . '-' . ++$i; return $name; }
    private function assertCustomer(User $user): void { if (!$this->isEnabled() || !$user->id || $user->isGuest() || !$user->hasRole('mercato-customer') || !$this->isVerified($user)) throw new WireException($this->commerce->_('Customer account access is required.'), 403); }
    private function applyProfile(User $user, array $data, bool $includeStructured): void { foreach (['first_name' => 'mrc_first_name', 'last_name' => 'mrc_last_name', 'phone' => 'mrc_phone'] as $key => $field) if ($user->hasField($field)) $user->set($field, substr(trim((string) ($data[$key] ?? $user->get($field))), 0, 200)); if ($includeStructured && isset($data['addresses'])) $user->mrc_customer_addresses = json_encode(array_values(array_slice((array) $data['addresses'], 0, 20)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); if ($includeStructured && isset($data['preferences'])) $user->mrc_customer_preferences = json_encode((array) $data['preferences'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function issueToken(User $user, string $type): string { $token = bin2hex(random_bytes(32)); $field = $type === 'verify' ? 'mrc_customer_verification' : 'mrc_customer_password_reset'; $user->of(false); $user->set($field, json_encode(['hash' => hash('sha256', $token), 'expires' => time() + max(5, (int) ($this->commerce->account_token_ttl_minutes ?? 60)) * 60])); $this->wire('users')->save($user); return $token; }
    private function validToken(?User $user, string $type, string $token): bool { if (!$user || !$user->id || $token === '') return false; $field = $type === 'verify' ? 'mrc_customer_verification' : 'mrc_customer_password_reset'; $data = json_decode((string) $user->get($field), true); return is_array($data) && !MercatoAccountPolicy::tokenExpired((int) ($data['expires'] ?? 0)) && hash_equals((string) ($data['hash'] ?? ''), hash('sha256', $token)); }
    private function clearToken(User $user, string $type): void { $user->set($type === 'verify' ? 'mrc_customer_verification' : 'mrc_customer_password_reset', ''); }
    private function sendSecurityEmail(User $user, string $link, string $message, string $event = 'account_security'): void { $this->commerce->notificationDeliveryService()->deliver($event, (string) $user->email, ['customer' => trim((string) ($user->mrc_first_name ?? '') . ' ' . (string) ($user->mrc_last_name ?? '')) ?: (string) $user->email, 'account_link' => $link, 'security_message' => $message], ['business_event_id' => hash('sha256', $event . '|' . $link), 'user_id' => (int) $user->id]); }
    private function attempts(): array { $data = $this->wire('session')->get(self::AUTH_SESSION_KEY); return is_array($data) ? $data : []; }
    private function isRateLimited(string $key): bool { $a = $this->attempts()[$key] ?? []; $since = time() - max(60, (int) ($this->commerce->account_login_window_seconds ?? 900)); return count(array_filter((array) $a, fn($t) => (int) $t >= $since)) >= max(1, (int) ($this->commerce->account_login_attempts ?? 5)); }
    private function recordAttempt(string $key): void { $all = $this->attempts(); $all[$key] = array_slice(array_merge((array) ($all[$key] ?? []), [time()]), -20); $this->wire('session')->set(self::AUTH_SESSION_KEY, $all); }
    private function clearAttempts(string $key): void { $all = $this->attempts(); unset($all[$key]); $this->wire('session')->set(self::AUTH_SESSION_KEY, $all); }
    private function record(string $event, User $user, array $data = []): void { $log = new MercatoEventLog(self::LOG_NAME); $log->setWire($this->wire()); $log->record(['event' => $event, 'status' => 'completed', 'user_id' => (int) $user->id, 'operator' => (string) ($this->wire('user')->name ?? 'system')] + $data); }
    private function analyticsAccountId(User $user): string { return (string) ($this->commerce->analytics_account_identifier ?? 'omit') === 'hash' ? hash('sha256', (string) $user->id . '|' . (string) $this->wire('config')->userAuthSalt) : ''; }
}
