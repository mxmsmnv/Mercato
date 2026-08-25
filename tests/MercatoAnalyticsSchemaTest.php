<?php
namespace ProcessWire;
require_once dirname(__DIR__) . '/src/Analytics/MercatoAnalyticsSchema.php';
$event=MercatoAnalyticsSchema::normalize('purchase',['event_id'=>'purchase:1','order_identifier'=>'MRC-1','currency'=>'EUR','value'=>91.25,'tax'=>12.25,'shipping'=>5,'discount'=>10,'coupon'=>'SAVE10','email'=>'private@example.test','shipping_address'=>'Hidden','items'=>[['product_id'=>7,'variant_id'=>'blue','quantity'=>2,'price'=>48.125,'signed_url'=>'https://secret']]],'hash');
if($event['schema']!==MercatoAnalyticsSchema::VERSION||$event['currency']!=='EUR'||$event['value']!==91.25||strlen((string)$event['order_identifier'])!==64)throw new \RuntimeException('Versioned commerce schema failed.');
$json=json_encode($event);foreach(['private@example.test','shipping_address','signed_url','https://secret']as$forbidden)if(str_contains((string)$json,$forbidden))throw new \RuntimeException('PII/secret leaked into analytics schema.');
$refund=MercatoAnalyticsSchema::normalize('refund',['event_id'=>'refund:1','currency'=>'USD','value'=>12.5,'items'=>[]]);if($refund['value']!==12.5)throw new \RuntimeException('Partial refund amount failed.');
echo "Mercato analytics schema tests passed.\n";
