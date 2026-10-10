<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') { echo "Mercato checkout economics integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'mercato.test'; $_SERVER['SERVER_NAME'] = 'mercato.test'; $_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$super = $wire->users->get('template=user, roles.name=superuser');
if (!$super || !$super->id) throw new WireException('A superuser is required for checkout economics fixtures.');
$wire->users->setCurrentUser($super); $wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Checkout economics fixtures are forbidden in production mode.');

final class MercatoCheckoutEconomicsTaxProvider implements MercatoTaxProviderInterface {
    public int $estimates = 0;
    public int $commits = 0;
    public function getTaxProviderKey(): string { return 'checkout-economics-fixture'; }
    public function estimate(array $context): array {
        $this->estimates++;
        $taxable = max(0.0, array_sum(array_column((array) $context['items'], 'line_total')) - (float) $context['discount']['amount'] + (float) $context['shipping']['amount']);
        return [
            'currency'=>(string)$context['currency'], 'display_mode'=>'excluded', 'total_tax'=>round($taxable * 0.1, 2), 'taxable_amount'=>$taxable,
            'lines'=>[['line_id'=>(string)$context['items'][0]['line_id'], 'tax_code'=>(string)$context['items'][0]['tax_code'], 'taxable_amount'=>$taxable-(float)$context['shipping']['amount'], 'tax'=>round(($taxable-(float)$context['shipping']['amount'])*0.1,2), 'rate'=>10, 'jurisdiction'=>'US-NY']],
            'shipping'=>['taxable_amount'=>(float)$context['shipping']['amount'], 'tax'=>round((float)$context['shipping']['amount']*0.1,2), 'rate'=>10, 'jurisdiction'=>'US-NY'],
            'jurisdictions'=>[['country'=>'US','region'=>'NY','name'=>'Fixture','type'=>'state','rate'=>10,'tax'=>round($taxable*0.1,2)]],
            'provider_reference'=>'tax-' . substr((string)$context['idempotency_key'], -12), 'idempotency_key'=>(string)$context['idempotency_key'],
        ];
    }
    public function commit(array $context): array { $this->commits++; return ['status'=>'committed','provider_reference'=>'commit-' . (int)$context['order']['id']]; }
    public function refund(array $context): array { return ['status'=>'refunded','amount'=>(float)$context['amount']]; }
    public function void(array $context): array { return ['status'=>'voided']; }
}

$checks = 0;
$expect = static function(bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) throw new \RuntimeException($message); };
$amount = static function(float $expected, float $actual, string $message) use ($expect): void { $expect(abs($expected-$actual) < 0.001, $message . " Expected {$expected}, got {$actual}."); };
$expectThrows = static function(callable $callable, string $message, string $contains='') use (&$checks): void { $checks++; try { $callable(); } catch (\Throwable $e) { if ($contains==='' || str_contains(strtolower($e->getMessage()), strtolower($contains))) return; throw new \RuntimeException($message . ' Unexpected: ' . $e->getMessage(), 0, $e); } throw new \RuntimeException($message); };

$runId = 'checkout-economics-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$product = null; $missingPriceProduct = null; $discounts = []; $orders = []; $orderEmails = []; $cacheKeys = []; $lockPaths = [];
$logsRoot = rtrim((string)$wire->config->paths->logs,'/') . '/';
$runtimeKeys = ['currency','markets_json','enabled_payment_methods','checkout_enabled','customer_accounts_mode','enabled_notification_events','notification_sender_email','analytics_enabled','enabled_fulfilment_methods','default_fulfilment_method','shipping_provider','shipping_provider_include_manual_rates','tax_provider','tax_price_behavior','tax_display_mode','tax_rounding_mode','tax_shipping','shipping_tax_rate','tax_provider_failure_policy','tax_provider_retries'];
$originalRuntime = []; foreach ($runtimeKeys as $key) $originalRuntime[$key] = $commerce->get($key);
$originalCart = $commerce->cart()->toArray();
$secret = (string)($wire->config->userAuthSalt ?: __FILE__);
$removeLogs = static function() use ($logsRoot,$runId): void { foreach(glob($logsRoot.'mercato-*.txt')?:[] as $path){$lines=file($path);if($lines===false)continue;$kept=array_values(array_filter($lines,static fn(string $line):bool=>!str_contains($line,$runId)));if($kept===$lines)continue;if($kept===[])@unlink($path);else file_put_contents($path,implode('',$kept),LOCK_EX);} };
$cleaned = false;
$cleanup = static function() use (&$cleaned,$wire,$commerce,&$orders,&$orderEmails,&$discounts,&$product,&$missingPriceProduct,$originalRuntime,$originalCart,&$cacheKeys,&$lockPaths,$removeLogs,$runId): void {
    if($cleaned)return;$cleaned=true;foreach($originalRuntime as$key=>$value)$commerce->set($key,$value);$commerce->cart($originalCart);$wire->session->remove('mrc_pending_order');
    foreach(array_reverse(array_unique($orders)) as$id){$page=$wire->pages->get((int)$id);if(!$page->id)continue;if(!isset($orderEmails[(int)$id])||strtolower((string)$page->mrc_email)!==strtolower((string)$orderEmails[(int)$id]))throw new WireException("Refusing to delete unexpected economics order {$page->id}.");$commerce->orderRepository()->releaseStockReservation($page,'checkout_economics_cleanup');$wire->pages->delete($page,true);}
    foreach(array_reverse($discounts) as$page){if(!$page instanceof Page||!$page->id)continue;$fresh=$wire->pages->get((int)$page->id);if($fresh->id){if(!str_starts_with((string)$fresh->name,'e2e-checkout-economics-'))throw new WireException("Refusing to delete unexpected economics discount {$fresh->id}.");$wire->pages->delete($fresh,true);}}
    foreach([$missingPriceProduct,$product] as$page){if(!$page instanceof Page||!$page->id)continue;$fresh=$wire->pages->get((int)$page->id);if($fresh->id){if(!str_starts_with((string)$fresh->name,'e2e-checkout-economics-'))throw new WireException("Refusing to delete unexpected economics product {$fresh->id}.");$wire->pages->delete($fresh,true);}}
    foreach(array_unique($cacheKeys) as$key)$wire->cache->delete($key);foreach(array_unique($lockPaths) as$path)if(is_file($path))@unlink($path);$removeLogs();
};
register_shutdown_function($cleanup);

$provider = new MercatoCheckoutEconomicsTaxProvider();
$commerce->addHookAfter('taxProviders', static function(HookEvent $event) use ($provider): void { $providers=is_array($event->return)?$event->return:[];$providers[$provider->getTaxProviderKey()]=$provider;$event->return=$providers; });

try {
    $settings = ['currency'=>'GBP','markets_json'=>json_encode([['id'=>'us','label'=>'United States','currency'=>'USD','countries'=>['US'],'language'=>'en','fulfilment_prices'=>['carrier_delivery'=>15]]],JSON_UNESCAPED_SLASHES),'enabled_payment_methods'=>['demo'],'checkout_enabled'=>true,'customer_accounts_mode'=>'optional','enabled_notification_events'=>[],'notification_sender_email'=>'','analytics_enabled'=>false,'enabled_fulfilment_methods'=>['carrier_delivery'],'default_fulfilment_method'=>'carrier_delivery','shipping_provider'=>'manual','shipping_provider_include_manual_rates'=>true,'tax_provider'=>'manual','tax_price_behavior'=>'included','tax_display_mode'=>'included','tax_rounding_mode'=>'line','tax_shipping'=>true,'shipping_tax_rate'=>20,'tax_provider_failure_policy'=>'fail_closed','tax_provider_retries'=>0];
    foreach($settings as$key=>$value)$commerce->set($key,$value);
    $parent=$wire->pages->get('/products/');$discountParent=$wire->pages->get('/discounts/');if(!$parent->id||!$discountParent->id)throw new WireException('Mercato product/discount fixture parents are missing.');
    $makeProduct=static function(string $suffix,bool $marketPrice) use($wire,$parent,$runId):Page{$p=new Page();$p->template='mrc-product';$p->parent=$parent;$p->name='e2e-checkout-economics-'.$suffix.'-'.$runId;$p->of(false);$p->title='Checkout Economics '.$suffix;$p->mrc_sku='CE-'.strtoupper(substr(hash('sha256',$runId.$suffix),0,10));$p->mrc_price=100;$p->mrc_shipping_price=10;$p->mrc_tax_rate=20;$p->mrc_tax_code='general';$p->mrc_product_type='physical';$p->mrc_product_status='active';$p->mrc_stock=20;$p->mrc_stock_policy='deny';$p->mrc_market_prices=$marketPrice?json_encode(['us'=>['price'=>120,'shipping_price'=>15]],JSON_UNESCAPED_SLASHES):'{}';$wire->pages->save($p);return$p;};
    $product=$makeProduct('product',true);$missingPriceProduct=$makeProduct('missing-price',false);
    $makeDiscount=static function(string $suffix,string $type,float $percent,float $fixed,float $minimum=0) use($wire,$discountParent,$runId,&$discounts):string{$p=new Page();$p->template='mrc-discount';$p->parent=$discountParent;$p->name='e2e-checkout-economics-'.$suffix.'-'.$runId;$p->of(false);$p->title='Checkout Economics '.$suffix;$p->mrc_discount_code='CE'.strtoupper(substr(hash('sha256',$runId.$suffix),0,10));$p->mrc_discount_active=1;$p->mrc_discount_type=$type;$p->mrc_discount_percent=$percent;$p->mrc_discount_amount=$fixed;$p->mrc_discount_minimum_order=$minimum;$p->mrc_discount_usage_limit=0;$p->mrc_discount_customer_limit=0;$wire->pages->save($p);$discounts[]=$p;return(string)$p->mrc_discount_code;};
    $percentage=$makeDiscount('percentage',MercatoDiscountType::PERCENTAGE,10,0);$fixed=$makeDiscount('fixed',MercatoDiscountType::FIXED,0,15);$free=$makeDiscount('free',MercatoDiscountType::FREE_SHIPPING,0,0);$marketMinimum=$makeDiscount('market-minimum',MercatoDiscountType::PERCENTAGE,10,0,1);
    $api=$commerce->headlessApiService();
    $scenarios=[
        'none-manual-default'=>['market'=>'default','discount'=>'','tax'=>'manual','subtotal'=>100,'shipping'=>10,'discount_amount'=>0,'tax_provider'=>'manual','total'=>110,'currency'=>'GBP'],
        'percentage-manual-default'=>['market'=>'default','discount'=>$percentage,'tax'=>'manual','subtotal'=>100,'shipping'=>10,'discount_amount'=>10,'tax_provider'=>'manual','total'=>100,'currency'=>'GBP'],
        'fixed-manual-default'=>['market'=>'default','discount'=>$fixed,'tax'=>'manual','subtotal'=>100,'shipping'=>10,'discount_amount'=>15,'tax_provider'=>'manual','total'=>95,'currency'=>'GBP'],
        'free-shipping-manual-default'=>['market'=>'default','discount'=>$free,'tax'=>'manual','subtotal'=>100,'shipping'=>10,'discount_amount'=>10,'tax_provider'=>'manual','total'=>100,'currency'=>'GBP'],
        'percentage-provider-default'=>['market'=>'default','discount'=>$percentage,'tax'=>$provider->getTaxProviderKey(),'subtotal'=>100,'shipping'=>10,'discount_amount'=>10,'tax_provider'=>$provider->getTaxProviderKey(),'tax_amount'=>10,'total'=>110,'currency'=>'GBP'],
        'percentage-manual-us'=>['market'=>'us','discount'=>$percentage,'tax'=>'manual','subtotal'=>120,'shipping'=>15,'discount_amount'=>12,'tax_provider'=>'manual','total'=>123,'currency'=>'USD'],
    ];
    foreach($scenarios as$name=>$scenario){
        $commerce->set('tax_provider',$scenario['tax']);$commerce->set('tax_price_behavior',$scenario['tax']===$provider->getTaxProviderKey()?'excluded':'included');$email='ce-'.substr(hash('sha256',$runId.'|'.$name),0,24).'@example.test';$body=['items'=>[['product_id'=>(int)$product->id,'quantity'=>1]],'customer'=>['first_name'=>'Checkout','last_name'=>'Economics','email'=>$email,'address'=>'1 Fixture Street','city'=>'New York','zip'=>'10001','country'=>'US','region'=>'NY'],'options'=>['market_id'=>$scenario['market'],'fulfilment_method'=>'carrier_delivery','payment_method'=>'demo','policy_accepted'=>true,'discount_code'=>$scenario['discount']]];
        $quote=$api->quote($body);$amount($scenario['subtotal'],(float)$quote['subtotal'],"{$name} quote subtotal mismatch.");$amount($scenario['shipping'],(float)$quote['shipping'],"{$name} quote shipping mismatch.");$amount($scenario['discount_amount'],(float)$quote['discount'],"{$name} quote discount mismatch.");$amount($scenario['total'],(float)$quote['total'],"{$name} quote total mismatch.");$expect((string)$quote['currency']===$scenario['currency']&&(string)$quote['market_id']===$scenario['market'],"{$name} quote market/currency mismatch.");
        $createKey='create-'.$runId.'-'.$name;$cacheKey='Mercato.api.idem.'.hash_hmac('sha256','create|'.$createKey,$secret);$cacheKeys[]=$cacheKey;$lockPaths[]=rtrim((string)$wire->config->paths->cache,'/').'/Mercato-headless-locks/'.hash('sha256',$cacheKey).'.lock';
        try { $created=$api->createCheckout($body,$createKey); } catch (MercatoHeadlessApiException $error) { throw new \RuntimeException("{$name} checkout failed: {$error->apiCode} " . json_encode($error->fields, JSON_UNESCAPED_SLASHES), 0, $error); } $order=$wire->pages->get('template=mrc-order,include=all,mrc_api_checkout_id='.$wire->sanitizer->selectorValue((string)$created['id']));if(!$order->id)throw new \RuntimeException("{$name} checkout order missing.");$orders[]=(int)$order->id;$orderEmails[(int)$order->id]=$email;
        $completed=$api->complete((string)$created['id'],(string)$created['token'],[],'complete-'.$runId.'-'.$name);$order=$wire->pages->getById((int)$order->id,['cache'=>false])->first();
        $expect(!empty($completed['payment_complete'])&&(string)$order->mrc_payment_status===MercatoPaymentStatus::PAID,"{$name} did not complete payment.");$amount($scenario['subtotal'],(float)$order->mrc_subtotal_amount,"{$name} persisted subtotal mismatch.");$amount($scenario['shipping'],(float)$order->mrc_shipping_amount,"{$name} persisted shipping mismatch.");$amount($scenario['discount_amount'],(float)$order->mrc_discount_total,"{$name} persisted discount mismatch.");$amount($scenario['total'],(float)$order->mrc_total_amount,"{$name} persisted total mismatch.");$expect((string)$order->mrc_currency===$scenario['currency']&&(string)$order->mrc_discount_code===$scenario['discount'],"{$name} persisted currency/discount mismatch.");
        $items=json_decode((string)$order->mrc_items,true,512,JSON_THROW_ON_ERROR);$tax=json_decode((string)$order->mrc_tax_details,true,512,JSON_THROW_ON_ERROR);$expect(($items[0]['market_id']??'default')===$scenario['market']&&(string)($items[0]['currency']??$scenario['currency'])===$scenario['currency'],"{$name} lost item market snapshot.");$expect((string)($tax['quote']['provider']??'')===$scenario['tax_provider']&&(string)($tax['quote']['input_snapshot']['currency']??'')===$scenario['currency'],"{$name} lost tax provider/currency snapshot.");if(isset($scenario['tax_amount']))$amount($scenario['tax_amount'],(float)$order->mrc_tax_amount,"{$name} provider tax mismatch.");
    }
    $expect((int)$wire->pages->getById((int)$product->id,['cache'=>false])->first()->mrc_stock===14,'Six checkout completions did not adjust stock exactly once.');
    $expect($provider->estimates===3&&$provider->commits===1,'Provider tax quote/create/finalize call counts changed.');
    $baseBody=['items'=>[['product_id'=>(int)$product->id,'quantity'=>1]],'customer'=>['first_name'=>'Invalid','last_name'=>'Boundary','email'=>$runId.'-invalid@example.test','address'=>'1 Fixture Street','city'=>'New York','zip'=>'10001','country'=>'US'],'options'=>['market_id'=>'us','fulfilment_method'=>'carrier_delivery','payment_method'=>'demo','policy_accepted'=>true]];
    foreach([[$fixed,'selected market'],[$free,'selected market'],[$marketMinimum,'selected market']] as[$code,$fragment]){$body=$baseBody;$body['options']['discount_code']=$code;$expectThrows(fn()=>$api->quote($body),'Unsupported non-default-market discount was accepted.',$fragment);}
    $missing=$baseBody;$missing['items'][0]['product_id']=(int)$missingPriceProduct->id;$expectThrows(fn()=>$api->quote($missing),'Missing explicit market price was accepted.','no explicit price');
    $invalidMarket=$baseBody;$invalidMarket['options']['market_id']='unknown-market';$expectThrows(fn()=>$api->quote($invalidMarket),'Unknown market was accepted.','unavailable');
    $stale=$commerce->variantService()->hydrateItem($product,['quantity'=>1]);$commerce->cart([$stale]);$expectThrows(fn()=>$commerce->initializePayment(['first_name'=>'Stale','last_name'=>'Cart','email'=>$runId.'-stale@example.test','payment_method'=>'demo','mrc_market_id'=>'us','fulfilment_method'=>'carrier_delivery','mrc_policy_accepted'=>1]),'Default-price cart was accepted for a non-default market.','stale');$commerce->cart([]);
    $orderCount=count($orders);$ownedPageIds=array_merge($orders,array_map(static fn(Page $page):int=>(int)$page->id,$discounts),[(int)$product->id,(int)$missingPriceProduct->id]);$cleanup();
    $statement=$wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');foreach(array_unique($ownedPageIds)as$id){$statement->execute([':id'=>(int)$id]);$expect((int)$statement->fetchColumn()===0,"Run-owned checkout economics page {$id} remained after cleanup.");}
    $residual='';foreach(glob($logsRoot.'mercato-*.txt')?:[] as$path)$residual.=(string)file_get_contents($path);$expect(!str_contains($residual,$runId),'Run-owned checkout economics log rows remained after cleanup.');
    echo "Mercato checkout economics integration tests passed: {$checks} assertions, {$orderCount} paid orders, 4 discounts, manual/provider tax, default/non-default markets; exact cleanup complete.\n";
} finally { $cleanup(); }
