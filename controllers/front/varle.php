<?php
if (!defined('_PS_VERSION_')) { exit; }

class moovarlevarleModuleFrontController extends ModuleFrontController
{
    private function cacheBase(){ return _PS_MODULE_DIR_ . 'moovarle/var/cache/'; }
    private function tryServeCache($langId,$shopId,$currencyIso){
        $base=$this->cacheBase(); $file=sprintf('%s%s-%d-%d-%s.xml',$base,'varle',$shopId,$langId,$currencyIso);
        if(is_file($file)){
            header('Content-Type: application/xml; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            $mtime=filemtime($file); header('Last-Modified: '.gmdate('D, d M Y H:i:s',$mtime).' GMT');
            $etag='W/"'.md5($file.$mtime.filesize($file)).'"'; header('ETag: '.$etag);
            header('Cache-Control: public, max-age=1800');
            if((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'])===$etag)){
                http_response_code(304); exit;
            }
            readfile($file); exit;
        }
    }
    public function initContent(){
        parent::initContent();
        $langId=(int)$this->context->language->id; $currency=$this->context->currency; $currencyIso=is_object($currency)&&isset($currency->iso_code)?$currency->iso_code:(is_string($currency)?$currency:'EUR'); $shopId=(int)$this->context->shop->id; 
        $this->tryServeCache($langId,$shopId,$currencyIso);
        http_response_code(404); header('Content-Type: application/json'); echo json_encode(['status'=>'cache-missing']); exit; 
    }
}
