<?php
if (!defined('_PS_VERSION_')) { exit; }

class Moovarle extends Module
{
    public function __construct()
    {
        $this->name = 'moovarle';
        $this->tab = 'advertising_marketing';
    $this->version = '1.1.0';
        $this->author = 'moonia';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->controllers = ['varle','cron'];

        parent::__construct();

        $this->displayName = $this->l('moovarle');
        $this->description = $this->l('Varle.lt marketplace XML export');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('moduleRoutes')
            && Configuration::updateValue('MOOVARLE_GLOBAL_DISCOUNT', 0)
            && Configuration::updateValue('MOOVARLE_PRICE_SOURCE', 'retail')
            && Configuration::updateValue('MOOVARLE_MARGIN_PERCENT', 0)
            && Configuration::updateValue('MOOVARLE_DELIVERY_DAYS', '3 d. d.')
            && Configuration::updateValue('MOOVARLE_CRON_TOKEN', Tools::passwdGen(24));
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('MOOVARLE_GLOBAL_DISCOUNT')
            && Configuration::deleteByName('MOOVARLE_PRICE_SOURCE')
            && Configuration::deleteByName('MOOVARLE_MARGIN_PERCENT')
            && Configuration::deleteByName('MOOVARLE_DELIVERY_DAYS')
            && Configuration::deleteByName('MOOVARLE_CRON_TOKEN');
    }

    public function hookModuleRoutes($params)
    {
        return [
            // Pretty URLs (no trailing slash)
            'module-moovarle-feed' => [
                'controller' => 'varle',
                'rule' => 'feed/varle.xml',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'module-moovarle-cron' => [
                'controller' => 'cron',
                'rule' => 'feed/varle/cron',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            // Pretty URLs (with trailing slash)
            'module-moovarle-feed-slash' => [
                'controller' => 'varle',
                'rule' => 'feed/varle.xml/',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'module-moovarle-cron-slash' => [
                'controller' => 'cron',
                'rule' => 'feed/varle/cron/',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }

    public function getContent()
    {
        $out = '';
        $adminUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ]);

        if (Tools::isSubmit('moovarle_save') || Tools::isSubmit('moovarle_regen')) {
            $discount = (float)Tools::getValue('global_discount', 0);
            if ($discount < 0) $discount = 0; if ($discount > 95) $discount = 95; // sanity
            $priceSource = (string)Tools::getValue('price_source', 'retail');
            if ($priceSource !== 'retail' && $priceSource !== 'wholesale') { $priceSource = 'retail'; }
            $margin = (float)Tools::getValue('margin_percent', 0);
            if ($margin < 0) $margin = 0; if ($margin > 500) $margin = 500; // guard
            $delivery = trim((string)Tools::getValue('delivery_days', '3 d. d.'));
            if ($delivery === '') { $delivery = '3 d. d.'; }
            Configuration::updateValue('MOOVARLE_GLOBAL_DISCOUNT', $discount);
            Configuration::updateValue('MOOVARLE_PRICE_SOURCE', $priceSource);
            Configuration::updateValue('MOOVARLE_MARGIN_PERCENT', $margin);
            Configuration::updateValue('MOOVARLE_DELIVERY_DAYS', $delivery);
            if (Tools::isSubmit('moovarle_save')) {
                Tools::redirectAdmin($adminUrl.'&moovarle_msg=saved');
            }
        }

        if (Tools::isSubmit('moovarle_regen')) {
            // Touch the cron with reset=1
            Tools::redirect($this->context->link->getModuleLink($this->name, 'cron', [
                'token' => Configuration::get('MOOVARLE_CRON_TOKEN'),
                'reset' => 1,
            ]));
        }

        $token = (string)Configuration::get('MOOVARLE_CRON_TOKEN');
        if ($token === '') { $token = Tools::passwdGen(24); Configuration::updateValue('MOOVARLE_CRON_TOKEN', $token); }

    $discount = (float)Configuration::get('MOOVARLE_GLOBAL_DISCOUNT');
    $priceSource = (string)Configuration::get('MOOVARLE_PRICE_SOURCE'); if($priceSource===''){ $priceSource='retail'; }
    $margin = (float)Configuration::get('MOOVARLE_MARGIN_PERCENT');
        $delivery = (string)Configuration::get('MOOVARLE_DELIVERY_DAYS'); if($delivery===''){ $delivery='3 d. d.'; }

        $baseUrl = __PS_BASE_URI__;
        $out .= '<div class="panel">';
        $out .= '<h3>'.$this->displayName.'</h3>';

        $out .= '<form method="post">';
    $out .= '<div class="form-group"><label>'.$this->l('Global discount (%)').'</label><input class="form-control" type="number" step="0.01" min="0" max="95" name="global_discount" value="'.htmlspecialchars((string)$discount, ENT_QUOTES, 'UTF-8').'"/></div>';
    $out .= '<div class="form-group"><label>'.$this->l('Price source').'</label>'
        .'<select class="form-control" name="price_source">'
        .'<option value="retail"'.($priceSource==='retail'?' selected':'').'>'.$this->l('Retail (tax incl.)').'</option>'
        .'<option value="wholesale"'.($priceSource==='wholesale'?' selected':'').'>'.$this->l('Wholesale + VAT + margin').'</option>'
        .'</select></div>';
    $out .= '<div class="form-group"><label>'.$this->l('Margin (%)').'</label><input class="form-control" type="number" step="0.01" min="0" max="500" name="margin_percent" value="'.htmlspecialchars((string)$margin, ENT_QUOTES, 'UTF-8').'"/></div>';
    $out .= '<div class="form-group"><label>'.$this->l('Delivery time (text)').'</label><input class="form-control" type="text" name="delivery_days" placeholder="3-5 d. d." value="'.htmlspecialchars((string)$delivery, ENT_QUOTES, 'UTF-8').'"/></div>';
        $out .= '<button class="btn btn-primary" name="moovarle_save" value="1">'.$this->l('Save settings').'</button> ';
        $out .= '<button class="btn btn-warning" name="moovarle_regen" value="1">'.$this->l('Regenerate export now').'</button>';
        $out .= '</form>';

        $out .= '<hr/>';
    $out .= '<p>'.$this->l('Feed URL:').' <code>'.htmlspecialchars($baseUrl.'feed/varle.xml', ENT_QUOTES, 'UTF-8').'</code></p>';
    $out .= '<p>'.$this->l('Cron (reset):').' <code>'.htmlspecialchars($baseUrl.'feed/varle/cron?reset=1&token='.$token, ENT_QUOTES, 'UTF-8').'</code></p>';
    // Legacy fallbacks
    $legacyFeed = $this->context->link->getModuleLink($this->name,'varle',[]);
    $legacyCron = $this->context->link->getModuleLink($this->name,'cron',['reset'=>1,'token'=>$token]);
    $out .= '<p>'.$this->l('Legacy feed URL (if pretty URL returns 404):').' <code>'.htmlspecialchars($legacyFeed, ENT_QUOTES, 'UTF-8').'</code></p>';
    $out .= '<p>'.$this->l('Legacy cron URL (reset):').' <code>'.htmlspecialchars($legacyCron, ENT_QUOTES, 'UTF-8').'</code></p>';
        $out .= '</div>';

        return $out;
    }
}
