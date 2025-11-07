<?php
if (!defined('_PS_VERSION_')) { exit; }

class moovarlecronModuleFrontController extends ModuleFrontController
{
    private function cfg($key, $default = null){
        // Prefer shop-scoped value via PS API
        $shopId = (int)$this->context->shop->id;
        $v = ($shopId>0)
            ? Configuration::get($key, null, null, $shopId)
            : Configuration::get($key);
        // If empty, resolve explicitly with shop-aware SQL fallback
        if($v === false || $v === null || $v === ''){
            if($shopId > 0){
                $v = Db::getInstance()->getValue(
                    'SELECT cs.value FROM '._DB_PREFIX_.'configuration_shop cs '
                    .'INNER JOIN '._DB_PREFIX_.'configuration c ON c.id_configuration = cs.id_configuration '
                    .'WHERE cs.id_shop='.(int)$shopId.' AND c.name=\''.pSQL($key).'\' '
                    .'ORDER BY cs.id_configuration DESC'
                );
            }
            if($v === false || $v === null || $v === ''){
                $v = Db::getInstance()->getValue('SELECT value FROM '._DB_PREFIX_.'configuration WHERE name=\''.pSQL($key).'\' ORDER BY id_configuration DESC');
            }
        }
        return ($v === false || $v === null || $v === '') ? $default : $v;
    }
    private function ensureDir($path){ if (!is_dir($path)) { @mkdir($path, 0775, true); } }
    private function cacheBase(){ return _PS_MODULE_DIR_ . 'moovarle/var/cache/'; }
    private function lockBase(){ return _PS_MODULE_DIR_ . 'moovarle/var/lock/'; }
    private function buildPaths($shopId,$langId,$currencyIso){ $base=$this->cacheBase(); $this->ensureDir($base); $tmp=sprintf('%svarle-%d-%d-%s.xml.tmp',$base,$shopId,$langId,$currencyIso); $fin=sprintf('%svarle-%d-%d-%s.xml',$base,$shopId,$langId,$currencyIso); $state=sprintf('%svarle-%d-%d-%s.state.json',$base,$shopId,$langId,$currencyIso); return [$tmp,$fin,$state]; }
    private function buildLock($shopId,$langId,$currencyIso){ $base=$this->lockBase(); $this->ensureDir($base); return sprintf('%svarle-%d-%d-%s.lock',$base,$shopId,$langId,$currencyIso); }
    private function openLock($lockPath){ $fh=fopen($lockPath,'c'); if ($fh && flock($fh,LOCK_EX|LOCK_NB)) { return $fh; } return null; }

    private function getTaxRateForDefaultCountry(Product $product){ $countryId=(int)Configuration::get('PS_COUNTRY_DEFAULT'); $address=new Address(); $address->id_country=$countryId; $address->id_state=0; $address->postcode=''; $rate=0.0; if((int)$product->id_tax_rules_group>0){ $tm=TaxManagerFactory::getManager($address,(int)$product->id_tax_rules_group); $calc=$tm->getTaxCalculator(); $rate=(float)$calc->getTotalRate(); } return $rate; }

    private function writeHeader($fh){ fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>\n  <products>\n"); }
    private function writeFooter($fh){ fwrite($fh, "  </products>\n</root>\n"); }

    private function cdata($s){ $s=(string)$s; return '<![CDATA['.str_replace(']]>',']]]]><![CDATA[>',$s).']]>'; }
    private function xmlElCdata($name,$value){ return '<'.$name.'>'.$this->cdata($value).'</'.$name.'>'; }
    private function xmlElRaw($name,$value){ $v=trim((string)$value); return '<'.$name.'>'.$v.'</'.$name.'>'; }
    private function joinPath(array $parts){ $clean=array(); foreach($parts as $p){ $t=trim((string)$p); if($t!==''){ $clean[]=$t; } } return implode(' -> ',$clean); }
    private function buildCategoryPath($categoryId,$langId){
        // Returns an array of breadcrumb category names (excluding Root/Home) from top to deepest.
        $list = array();
        if($categoryId){
            $cat = new Category((int)$categoryId,$langId);
            if(Validate::isLoadedObject($cat)){
                $parents = $cat->getParentsCategories($langId);
                $homeId = (int)Configuration::get('PS_HOME_CATEGORY'); if($homeId<=0){ $homeId=2; }
                if(is_array($parents)){
                    foreach(array_reverse($parents) as $pc){
                        $cid = isset($pc['id_category']) ? (int)$pc['id_category'] : 0;
                        if($cid<=1 || $cid===$homeId){ continue; }
                        $nm = isset($pc['name']) ? $pc['name'] : '';
                        if($nm!==''){ $list[] = $nm; }
                    }
                }
                if(!$list){
                    if((int)$cat->id>1 && (int)$cat->id!==$homeId){ $list[] = $cat->name; }
                }
            }
        }
        return $list;
    }

    private function getAllImageUrls($productId,$link,$linkRewrite){
        $urls=array();
        $imgs=Image::getImages((int)$this->context->language->id,(int)$productId);
        if(is_array($imgs)){
            // sort by position asc
            usort($imgs,function($a,$b){ return ((int)$a['position']) <=> ((int)$b['position']); });
            foreach($imgs as $im){
                if(empty($im['id_image'])){ continue; }
                $u=$link->getImageLink($linkRewrite,$im['id_image'],'large_default');
                if(strpos($u,'//')===0){ $u='https:'.$u; }
                $urls[]=$u;
            }
        }
        return $urls;
    }

    private function formatDeliveryText($text){
        $t=trim((string)$text);
        if($t===''){ $t='3 d. d.'; }
        // Backward compatibility: if value is purely numeric, append suffix
        if(preg_match('/^\d+$/', $t)){
            $t = $t.' d. d.';
        }
        return $t;
    }

    public function initContent()
    {
        parent::initContent();
        $token=Tools::getValue('token'); $remote=Tools::getRemoteAddr(); $cfgToken=(string)Configuration::get('MOOVARLE_CRON_TOKEN');
        if(!in_array($remote,['127.0.0.1','::1']) && ($cfgToken===''||$token!==$cfgToken)){ header('Content-Type: application/json'); http_response_code(403); echo json_encode(['status'=>'forbidden']); exit; }

        $langId=(int)$this->context->language->id; $shopId=(int)$this->context->shop->id; $currency=$this->context->currency; $currencyIso=is_object($currency)&&isset($currency->iso_code)?$currency->iso_code:(is_string($currency)?$currency:'EUR');
        list($tmpFile,$finalFile,$stateFile)=$this->buildPaths($shopId,$langId,$currencyIso); $lockFile=$this->buildLock($shopId,$langId,$currencyIso); $lock=$this->openLock($lockFile); if(!$lock){ header('Content-Type: application/json'); echo json_encode(['status'=>'busy']); exit; }

    $defSize = (int)Configuration::get('MOOFEEDS_DEF_SIZE'); if($defSize<1){$defSize=1000;} // reuse feed defaults if present
    $defSteps= (int)Configuration::get('MOOFEEDS_DEF_STEPS'); if($defSteps<1){$defSteps=3;}
    $size=max(1,(int)Tools::getValue('size',$defSize)); $maxSteps=max(1,(int)Tools::getValue('max_steps',$defSteps)); $reset=(bool)Tools::getValue('reset',false);
    $loop=(bool)Tools::getValue('loop',false); $timeBudget=(int)Tools::getValue('time',18); if($timeBudget<5){$timeBudget=5;} if($timeBudget>60){$timeBudget=60;} $t0=microtime(true);

        $state=['last_id'=>0,'processed'=>0,'started'=>false];
        if(file_exists($stateFile)&&!$reset){ $json=@file_get_contents($stateFile); if($json){ $s=json_decode($json,true); if(is_array($s)){ $state=array_merge($state,$s); } } }
        else if($reset||!file_exists($finalFile)){
            $fh=fopen($tmpFile,'w'); $this->writeHeader($fh); fclose($fh); @chmod($tmpFile,0664); @unlink($finalFile); $state=['last_id'=>0,'processed'=>0,'started'=>true];
        } else { fclose($lock); header('Content-Type: application/json'); echo json_encode(['status'=>'done','file'=>$finalFile]); exit; }

    $discount = (float)$this->cfg('MOOVARLE_GLOBAL_DISCOUNT', 0); if($discount<0){$discount=0;} if($discount>95){$discount=95;}
    $priceSource = (string)$this->cfg('MOOVARLE_PRICE_SOURCE', 'retail'); if($priceSource===''){ $priceSource='retail'; }
    $margin = (float)$this->cfg('MOOVARLE_MARGIN_PERCENT', 0); if($margin<0){$margin=0;} if($margin>500){$margin=500;}
        $deliveryText = (string)$this->cfg('MOOVARLE_DELIVERY_DAYS', '3 d. d.'); if(trim($deliveryText)===''){ $deliveryText='3 d. d.'; }

        $step=0; $db=Db::getInstance(); $link=$this->context->link;
    while($step<$maxSteps && (!$loop || (microtime(true)-$t0)<$timeBudget)){
            $sql=new DbQuery();
            $sql->select('p.id_product, pl.name, pl.description_short, pl.link_rewrite, p.id_manufacturer, p.id_category_default, sa.quantity, p.reference, p.ean13, p.upc')
                ->from('product','p')
                ->innerJoin('product_shop','ps','ps.id_product = p.id_product AND ps.id_shop='.(int)$shopId)
                ->innerJoin('product_lang','pl','pl.id_product = p.id_product AND pl.id_lang='.(int)$langId.' AND pl.id_shop='.(int)$shopId)
                ->leftJoin('stock_available','sa','sa.id_product = p.id_product AND sa.id_product_attribute = 0')
                ->where('ps.active = 1')
                ->where('sa.quantity > 0')
                ->where('p.id_product > '.(int)$state['last_id'])
                ->orderBy('p.id_product ASC')
                ->limit($size);
            $rows=$db->executeS($sql);
            if(!$rows||count($rows)===0){
                // close and rotate
                $fh=fopen($tmpFile,'a'); $this->writeFooter($fh); fclose($fh);
                $renOk=@rename($tmpFile,$finalFile); if(!$renOk){ if(@copy($tmpFile,$finalFile)){ @unlink($tmpFile); $renOk=true; } }
                if($renOk){ @chmod($finalFile,0664);} $fsize=is_file($finalFile)?(int)@filesize($finalFile):0; @unlink($stateFile); fclose($lock); @unlink($lockFile);
                header('Content-Type: application/json'); echo json_encode(['status'=>'done','file'=>$finalFile,'size'=>$fsize,'renamed'=>(bool)$renOk]); exit; }

            $fh=fopen($tmpFile,'a');
            foreach($rows as $row){
                $productId=(int)$row['id_product']; $state['last_id']=$productId; $prodObj=new Product($productId,false,$langId);
                $url=$link->getProductLink($productId);
                $images=$this->getAllImageUrls($productId,$link,$row['link_rewrite']);
                // Pricing base: configurable source (retail or wholesale)
                $hasVariants=(bool)Combination::isFeatureActive() && (int)Product::getDefaultAttribute($productId)!==0 && count($prodObj->getAttributeCombinations($langId))>0;
                $computedBase = 0.0;
                if($priceSource === 'retail'){
                    // Use PrestaShop retail price (tax incl.), no reductions, no group reduction
                    if($hasVariants){
                        $combsTmp=$prodObj->getAttributeCombinations($langId);
                        $byIpa=array(); foreach($combsTmp as $c){ $byIpa[(int)$c['id_product_attribute']] = true; }
                        foreach(array_keys($byIpa) as $ipa){
                            $spo=null;
                            $p=(float)Product::getPriceStatic($productId, true, (int)$ipa, 2, null, false, false, 1, false, null, null, null, $spo, true, false, null, false);
                            if($p > $computedBase){ $computedBase = $p; }
                        }
                    } else {
                        $spo=null;
                        $computedBase=(float)Product::getPriceStatic($productId, true, null, 2, null, false, false, 1, false, null, null, null, $spo, true, false, null, false);
                    }
                } else {
                    // Wholesale path: wholesale (net) -> VAT 21% -> max across variants
                    $VAT_FACTOR = 1.21;
                    if($hasVariants){
                        $combsTmp=$prodObj->getAttributeCombinations($langId);
                        $byIpa=array(); foreach($combsTmp as $c){ $byIpa[(int)$c['id_product_attribute']] = true; }
                        foreach(array_keys($byIpa) as $ipa){
                            $wh=(float)Db::getInstance()->getValue('SELECT wholesale_price FROM '._DB_PREFIX_.'product_attribute WHERE id_product_attribute='.(int)$ipa);
                            if($wh<=0){ continue; }
                            $gross = $wh * $VAT_FACTOR;
                            if($gross > $computedBase){ $computedBase = $gross; }
                        }
                    } else {
                        $wh=(float)$prodObj->wholesale_price;
                        if($wh>0){ $computedBase = $wh * 1.21; }
                    }
                    // Fallback: if no wholesale found, use current shop price incl. tax as last resort
                    if($computedBase <= 0){
                        $fallbackIncl=(float)Product::getPriceStatic($productId,true,null,2,null,false,false,1,false);
                        $computedBase = $fallbackIncl;
                    }
                }
                // Apply module margin and optional discount
                $computedBaseWithMargin = $computedBase * (1 + $margin/100.0);
                $oldPrice = number_format($computedBaseWithMargin,2,'.','');
                if($discount>0){
                    $priceVal = $computedBaseWithMargin * (1 - $discount/100.0);
                } else {
                    $priceVal = $computedBaseWithMargin;
                }
                $price=number_format($priceVal,2,'.','');
                $brand=''; if(!empty($row['id_manufacturer'])){ $man=new Manufacturer((int)$row['id_manufacturer'],$langId); $brand=(string)$man->name; }
                $breadcrumb = $this->buildCategoryPath((int)$row['id_category_default'],$langId);
                $deepestOriginal = end($breadcrumb);
                $mappedCategory = null; // will be set via map or fallback string
                // Category mapping load (YAML cached static)
                static $catMap = null; if($catMap===null){
                    $mapFile = _PS_MODULE_DIR_.'moovarle/config/category_map.yaml';
                    $catMap = array();
                    if(is_file($mapFile)){
                        $raw = @file($mapFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
                        if($raw){
                            $current = array();
                            foreach($raw as $ln){
                                $trim = trim($ln);
                                if($trim===''){ continue; }
                                if(strpos($trim,'- ')===0){
                                    if(!empty($current)){ // commit previous
                                        if(isset($current['id_category']) && isset($current['marketplace_category'])){
                                            $catMap[(int)$current['id_category']] = $current['marketplace_category'];
                                        }
                                    }
                                    $current = array();
                                    $trim = substr($trim,2); // remove '- '
                                    // maybe inline key: value on first line
                                    if(strpos($trim,':')!==false){
                                        list($k,$v)=array_map('trim', explode(':',$trim,2));
                                        if($k!==''){ $current[$k]=$v; }
                                    }
                                } elseif(strpos($trim,':')!==false){
                                    list($k,$v)=array_map('trim', explode(':',$trim,2));
                                    if($k!==''){ $current[$k]=$v; }
                                }
                            }
                            if(!empty($current) && isset($current['id_category']) && isset($current['marketplace_category'])){
                                $catMap[(int)$current['id_category']] = $current['marketplace_category'];
                            }
                        }
                    }
                }
                // Attempt mapping by id_category_default first (look up full object id)
                $mappedId = (int)$row['id_category_default'];
                if(isset($catMap[$mappedId])){ 
                    $mappedCategory = $catMap[$mappedId]; 
                } else {
                    // Explicit fallback when id not present in YAML
                    $mappedCategory = 'Apatinis trikotažas moterims';
                }

                $features=$prodObj->getFrontFeatures($langId);

                $xml = "    <product>\n";
                $xml.= '      '.$this->xmlElRaw('id',$productId)."\n";
                // Categories: emit single deepest mapped category
                $xml.= "      <categories>\n";
                if($mappedCategory && $mappedCategory!==''){ $xml.='        '.$this->xmlElCdata('category',$mappedCategory)."\n"; }
                $xml.= "      </categories>\n";
                $xml.= '      '.$this->xmlElCdata('title',$row['name'])."\n";
                $descHtml=(string)$prodObj->description; if($descHtml===''){ $descHtml=(string)$row['description_short']; }
                $xml.= '      '.$this->xmlElCdata('description',$descHtml)."\n";
                $xml.= '      '.$this->xmlElRaw('price',$price)."\n";
                $xml.= '      '.$this->xmlElCdata('delivery_text',$this->formatDeliveryText($deliveryText))."\n";
                // Images
                $xml.= "      <images>\n";
                foreach($images as $iu){ $xml.='        '.$this->xmlElCdata('image',$iu)."\n"; }
                $xml.= "      </images>\n";

                // Stock and barcodes at product level only if no variants
                if(!$hasVariants){
                    $quantity=(int)$row['quantity'];
                    $xml.= '      '.$this->xmlElRaw('quantity',$quantity)."\n";
                    $barcodeFmt = !empty($row['ean13']) ? 'EAN' : (!empty($row['upc']) ? 'UPC' : '');
                    if($barcodeFmt!==''){ $xml.= '      '.$this->xmlElRaw('barcode_format',$barcodeFmt)."\n"; }
                    if($barcodeFmt==='EAN'){ $xml.= '      '.$this->xmlElRaw('barcode',$row['ean13'])."\n"; }
                    elseif($barcodeFmt==='UPC'){ $xml.= '      '.$this->xmlElRaw('barcode',$row['upc'])."\n"; }
                } else {
                    // Even with variants, template shows barcode_format at product level
                    $xml.= '      '.$this->xmlElRaw('barcode_format','EAN')."\n";
                }

                // Variants
                if($hasVariants){
                    $xml.="      <variants>\n";
                    $combs=$prodObj->getAttributeCombinations($langId);
                    // group by id_product_attribute
                    $byAttr=array();
                    foreach($combs as $c){ $byAttr[(int)$c['id_product_attribute']][]=$c; }
                    foreach($byAttr as $ipa=>$items){
                        $groups=array(); $values=array();
                        foreach($items as $it){ $groups[]=$it['group_name']; $values[]=$it['attribute_name']; }
                        // Force Varle-required group title
                        $groupTitle='Dydis';
                        $title=implode(' / ',$values);
                        $qty=(int)StockAvailable::getQuantityAvailableByProduct($productId,$ipa);
                        // Fetch EAN/UPC reliably from product_attribute table
                        $pa = Db::getInstance()->getRow('SELECT ean13, upc, reference FROM '._DB_PREFIX_.'product_attribute WHERE id_product_attribute='.(int)$ipa);
                        $ean = isset($pa['ean13']) ? (string)$pa['ean13'] : '';
                        $upc = isset($pa['upc']) ? (string)$pa['upc'] : '';
                        $ref = isset($pa['reference']) ? (string)$pa['reference'] : '';
                        // Variant price is static 0.00 for Varle
                        $deltaStr=number_format(0,2,'.','');

                        $xml.='        <variant group_title="'.htmlspecialchars($groupTitle,ENT_XML1|ENT_COMPAT,'UTF-8').'">' . "\n";
                        $xml.='          '.$this->xmlElCdata('title',$title)."\n";
                        $xml.='          '.$this->xmlElRaw('quantity',$qty)."\n";
                        // Always include barcode field (may be empty)
                        $barcodeVal = ($ean!=='') ? $ean : (($upc!=='') ? $upc : '');
                        $xml.='          '.$this->xmlElRaw('barcode',$barcodeVal)."\n";
                        $xml.='          '.$this->xmlElRaw('price',$deltaStr)."\n";
                        $xml.='        </variant>' . "\n";
                    }
                    $xml.="      </variants>\n";
                }

                // Recommended
                if(!empty($row['reference'])){ $xml.='      '.$this->xmlElCdata('model',$row['reference'])."\n"; }
                $xml.='      '.$this->xmlElRaw('weight',number_format(0.3,3,'.',''))."\n";
                if($brand!==''){ $xml.='      '.$this->xmlElCdata('manufacturer',$brand)."\n"; }
                // Attributes: add category breadcrumb as individual attributes first
                $xml.="      <attributes>\n";
                foreach($breadcrumb as $crumb){
                    $xml.='        <attribute title="Tipas">'.$this->cdata($crumb).'</attribute>'."\n";
                }
                if(!empty($features)){
                    foreach($features as $f){ $n=trim((string)$f['name']); $v=trim((string)$f['value']); if($n===''||$v===''){continue;} $title=htmlspecialchars($n,ENT_XML1|ENT_COMPAT,'UTF-8'); $xml.='        <attribute title="'.$title.'">'.$this->cdata($v).'</attribute>'."\n"; }
                }
                $xml.="      </attributes>\n";

                // Emit price_old only when discount is applied
                if($discount>0 && $oldPrice!==''){
                    $xml.='      '.$this->xmlElRaw('price_old',$oldPrice)."\n";
                }
                $xml.='      '.$this->xmlElCdata('url',$url)."\n";

                $xml .= "    </product>\n";

                fwrite($fh,$xml);
                $state['processed']++;
            }
            fclose($fh);
            file_put_contents($stateFile,json_encode($state+['ts'=>time()]));
            $step++;
        }

        fclose($lock); header('Content-Type: application/json'); echo json_encode(['status'=>'progress','state'=>$state]); exit;
    }
}
