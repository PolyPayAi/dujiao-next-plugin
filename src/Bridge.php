<?php
namespace PolyPayPlugin;

class Bridge
{
    private array $config;
    private Store $store;
    private $api;
    private $notify;

    /** Inject transport boundaries so protocol and lifecycle tests need no live payment. */
    public function __construct(array $config, Store $store, callable $api, callable $notify)
    {
        foreach (['pid', 'epay_key', 'merchant_id', 'environment', 'base_url', 'notify_url', 'return_url', 'usd_rate'] as $key) {
            if (!is_string($config[$key] ?? null) || $config[$key] === '') throw new \RuntimeException('Missing configuration: ' . $key);
        }
        if (strlen($config['epay_key']) < 32 || $config['environment'] !== 'production') {
            throw new \RuntimeException('Invalid bridge key or environment');
        }
        foreach (['base_url', 'notify_url', 'return_url'] as $key) Support::url($config[$key]);
        $this->config = $config;
        $this->store = $store;
        $this->api = $api;
        $this->notify = $notify;
    }

    /** Produce the exact EPay v1 canonical MD5 signature used by Dujiao-Next. */
    public static function sign(array $parameters, string $key): string
    {
        unset($parameters['sign'], $parameters['sign_type']);
        $parameters = array_filter($parameters, static function ($value) { return $value !== ''; });
        ksort($parameters, SORT_STRING);
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($value)) throw new \InvalidArgumentException('Only scalar form strings are accepted', 400);
            $pairs[] = $name . '=' . $value;
        }
        return md5(implode('&', $pairs) . $key);
    }

    /** Validate signed host input and create or reuse the same immutable payment attempt. */
    public function checkout(array $input): array
    {
        foreach ($input as $value) if (!is_string($value)) throw new \InvalidArgumentException('Invalid form value', 400);
        if (($input['pid'] ?? '') !== $this->config['pid'] || ($input['sign_type'] ?? '') !== 'MD5'
            || !hash_equals(self::sign($input, $this->config['epay_key']), strtolower($input['sign'] ?? ''))) {
            throw new \UnexpectedValueException('Invalid merchant signature', 401);
        }
        $id = $input['out_trade_no'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) throw new \InvalidArgumentException('Invalid order identifier', 400);
        if (!in_array($input['type'] ?? '', ['alipay', 'wxpay', 'qqpay'], true)) throw new \InvalidArgumentException('Unsupported EPay transport type', 400);
        if (($input['notify_url'] ?? '') !== $this->config['notify_url']) throw new \InvalidArgumentException('Notification URL mismatch', 400);
        $return = Support::url($input['return_url'] ?? '');
        // Dujiao-Next appends order tracking query parameters to the configured return URL.
        if (explode('?', $return, 2)[0] !== $this->config['return_url']) throw new \InvalidArgumentException('Return URL mismatch', 400);
        $amount = Support::amount($input['money'] ?? '');
        return $this->store->locked($id, function (array $record) use ($id, $input, $return, $amount): array {
            if ($record) {
                if ($record['amount'] !== $amount || $record['return_url'] !== $return || $record['type'] !== $input['type']
                    || $record['merchant_id'] !== $this->config['merchant_id'] || $record['environment'] !== $this->config['environment']) {
                    throw new \UnexpectedValueException('Order parameters changed; create a new payment attempt', 409);
                }
                if (in_array($record['state'], ['cancelled', 'expired', 'paid'], true)) throw new \UnexpectedValueException('Payment attempt is terminal', 409);
                if (!empty($record['checkout_url'])) return $record;
            } else {
                $record = ['host_order' => $id, 'mch_order_id' => 'DJ' . substr(hash('sha256', $this->config['pid'] . '|' . $id), 0, 30),
                    'amount' => $amount, 'usd_amount' => Support::usd($amount, $this->config['usd_rate']), 'rate' => $this->config['usd_rate'],
                    'merchant_id' => $this->config['merchant_id'], 'environment' => $this->config['environment'], 'return_url' => $return, 'type' => $input['type'],
                    'state' => 'creating', 'notified' => false];
                // Persist pricing before the remote request so an ambiguous retry cannot change it.
                $this->store->save($id, $record);
            }
            $result = ($this->api)('checkout', ['mch_order_id' => $record['mch_order_id'], 'amount' => $record['usd_amount'],
                'notify_url' => rtrim($this->config['base_url'], '/') . '/webhook.php?order=' . rawurlencode($id),
                'redirect_url' => $record['return_url']]);
            if (empty($result['checkout_url'])) throw new \RuntimeException('Incomplete checkout response');
            $detail = ($this->api)('detail', ['mch_order_id' => $record['mch_order_id']]);
            if (empty($detail['trade_id'])) throw new \RuntimeException('Order lookup did not return a trade ID');
            $record['trade_id'] = (string)$detail['trade_id'];
            $record['checkout_url'] = Support::url($result['checkout_url']);
            $record['state'] = 'pending';
            $this->store->save($id, $record);
            return $record;
        });
    }

    /** Durably accept a verified payment, then deliver an independently signed host callback. */
    public function paid(string $id, array $event): void
    {
        $this->store->locked($id, function (array $record) use ($id, $event): void {
            if (!$record || empty($record['trade_id']) || $record['merchant_id'] !== $this->config['merchant_id']
                || $record['environment'] !== $this->config['environment']) throw new \UnexpectedValueException('Unknown payment attempt', 409);
            Support::paid($event, $record);
            $record['state'] = 'paid';
            $record['event_id'] = $event['event_id'];
            $this->store->save($id, $record);
            if (!$record['notified']) $this->deliver($id, $record);
        });
    }

    /** Retry durable delivery without requiring another webhook or payment. */
    public function retry(string $id): void
    {
        $this->store->locked($id, function (array $record) use ($id): void {
            if (($record['state'] ?? '') !== 'paid' || $record['merchant_id'] !== $this->config['merchant_id']
                || $record['environment'] !== $this->config['environment']) throw new \RuntimeException('No accepted paid event');
            if (!$record['notified']) $this->deliver($id, $record);
        });
    }

    /** Send the original host amount; never use the USD or token amount as host accounting. */
    private function deliver(string $id, array $record): void
    {
        $form = ['pid' => $this->config['pid'], 'type' => $record['type'], 'out_trade_no' => $id,
            'trade_no' => $record['trade_id'], 'money' => $record['amount'], 'trade_status' => 'TRADE_SUCCESS'];
        $form['sign'] = self::sign($form, $this->config['epay_key']);
        $form['sign_type'] = 'MD5';
        if (!($this->notify)($this->config['notify_url'], $form)) throw new \RuntimeException('Host callback not acknowledged; run retry');
        $record['notified'] = true;
        $this->store->save($id, $record);
    }

    /** Reconcile before cancelling; paid wins a race and is delivered using the stored snapshot. */
    public function maintain(string $id, bool $cancel): array
    {
        return $this->store->locked($id, function (array $record) use ($id, $cancel): array {
            if (empty($record['trade_id']) || $record['merchant_id'] !== $this->config['merchant_id']
                || $record['environment'] !== $this->config['environment']) throw new \RuntimeException('Unknown payment attempt');
            if ($record['state'] === 'paid') {
                if (!$record['notified']) $this->deliver($id, $record);
                return ['state' => 'paid'];
            }
            $remote = ($this->api)('detail', ['trade_id' => $record['trade_id']]);
            if (in_array($remote['status'] ?? null, [2, 7], true)) {
                $event = $remote + ['order_no' => $record['mch_order_id'], 'environment' => $record['environment'], 'event_id' => 'query-' . $record['trade_id']];
                Support::paid($event, $record);
                $record['state'] = 'paid';
                $this->store->save($id, $record);
                if (!$record['notified']) $this->deliver($id, $record);
                return ['state' => 'paid'];
            }
            if ($cancel && in_array($remote['status'] ?? null, [0, 1], true)) {
                try { ($this->api)('cancel', ['trade_id' => $record['trade_id']]); }
                catch (\Throwable $error) { /* Reconcile an ambiguous cancellation response below. */ }
                $remote = ($this->api)('detail', ['trade_id' => $record['trade_id']]);
                if (in_array($remote['status'] ?? null, [2, 7], true)) throw new \RuntimeException('Payment won cancellation race; run reconcile to deliver');
            }
            $states = [0 => 'pending', 1 => 'pending', 3 => 'expired', 4 => 'cancelled', 6 => 'pending'];
            $record['state'] = $states[$remote['status'] ?? -1] ?? 'unknown';
            $this->store->save($id, $record);
            if ($cancel && !in_array($record['state'], ['expired', 'cancelled'], true)) throw new \RuntimeException('Cancellation did not converge; reconcile before retrying');
            return ['state' => $record['state']];
        });
    }
}
