<?php
/**
 * @copyright   Copyright (c) http://careyshop.cn All rights reserved.
 *
 * CareyShop    订单管理模型
 *
 * @author      zxm <252404501@qq.com>
 * @date        2017/7/29
 */

namespace app\common\model;

use app\common\service\Cart as CartSer;
use think\helper\Time;
use think\Config;

class Order extends CareyShop
{
    /**
     * 商品折扣数据
     * @var array
     */
    private $discountData = [];

    /**
     * 创建订单数据
     * @var array
     */
    private $orderData = [];

    /**
     * 购物车数据
     * @var array
     */
    private $cartData = [];

    /**
     * 外部提交数据
     * @var array
     */
    private $dataParams = [];

    /**
     * 是否需要自动写入时间戳
     * @var bool
     */
    protected $autoWriteTimestamp = true;

    /**
     * 隐藏属性
     * @var array
     */
    protected $hidden = [
        'parent_id',
        'create_user_id',
    ];

    /**
     * 只读属性
     * @var array
     */
    protected $readonly = [
        'order_id',
        'parent_id',
        'order_no',
        'user_id',
        'create_user_id',
    ];

    /**
     * 字段类型或者格式转换
     * @var array
     */
    protected $type = [
        'order_id'        => 'integer',
        'parent_id'       => 'integer',
        'user_id'         => 'integer',
        'pay_amount'      => 'float',
        'goods_amount'    => 'float',
        'total_amount'    => 'float',
        'delivery_fee'    => 'float',
        'use_money'       => 'float',
        'use_level'       => 'float',
        'use_integral'    => 'float',
        'use_coupon'      => 'float',
        'use_discount'    => 'float',
        'use_promotion'   => 'float',
        'use_card'        => 'float',
        'integral_pct'    => 'float',
        'delivery_id'     => 'integer',
        'country'         => 'integer',
        'region_list'     => 'array',
        'invoice_type'    => 'integer',
        'invoice_amount'  => 'float',
        'trade_status'    => 'integer',
        'delivery_status' => 'integer',
        'payment_status'  => 'integer',
        'create_user_id'  => 'integer',
        'is_give'         => 'integer',
        'adjustment'      => 'float',
        'give_integral'   => 'integer',
        'give_coupon'     => 'array',
        'payment_time'    => 'timestamp',
        'picking_time'    => 'timestamp',
        'delivery_time'   => 'timestamp',
        'finished_time'   => 'timestamp',
        'is_delete'       => 'integer',
    ];

    /**
     * 关联订单商品
     * @access public
     * @return mixed
     */
    public function getOrderGoods()
    {
        return $this->hasMany('OrderGoods');
    }

    /**
     * 关联配送方式
     * @access public
     * @return mixed
     */
    public function getDelivery()
    {
        return $this
            ->hasOne('Delivery', 'delivery_id', 'delivery_id')
            ->field('delivery_id,alias');
    }

    /**
     * 关联操作日志
     * @access public
     * @return mixed
     */
    public function getOrderLog()
    {
        return $this->hasMany('OrderLog');
    }

    /**
     * hasOne cs_user
     * @access public
     * @return mixed
     */
    public function getUser()
    {
        return $this
            ->hasOne('User', 'user_id', 'user_id', [], 'left')
            ->field('user_id,username,nickname,level_icon,head_pic');
    }

    /**
     * 生成唯一订单号
     * @access private
     * @return string
     */
    private function getOrderNo()
    {
        do {
            $orderNo = get_order_no('PO_');
        } while (self::checkUnique(['order_no' => ['eq', $orderNo]]));

        return $orderNo;
    }

    /**
     * 计算商品折扣额(实际返回折扣了多少金额)
     * @access private
     * @param  int   $goodsId 商品编号
     * @param  float $price   商品价格
     * @return float
     */
    private function calculateDiscountGoods($goodsId, $price)
    {
        foreach ($this->discountData as $value) {
            if ($value['goods_id'] !== $goodsId) {
                continue;
            }

            // 折扣
            if (0 == $value['type']) {
                return $price - ($price * ($value['discount'] / 100));
            }

            // 减价
            if (1 == $value['type']) {
                return $value['discount'];
            }

            // 固定价
            if (2 == $value['type']) {
                return $price - $value['discount'];
            }

            // 优惠劵
            if (3 == $value['type']) {
                $this->orderData['give_coupon'][] = (int)$value['discount'];
                break;
            }
        }

        return 0;
    }

    /**
     * 获取订单确认或提交订单
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function confirmOrderList($data)
    {
        if (!$this->validateData($data, 'Order')) {
            return false;
        }

        // 获取购物车数据,并获取关联商品与规格数据
        $clientId = get_client_id();
        $cartMap['user_id'] = ['eq', $clientId];
        $isBuyNow = isset($data['is_submit']) && $data['is_submit'] == 1 ? true : false;

        switch ($data['type']) {
            case 'cart':
                $cartMap['is_selected'] = ['eq', 1];
                $cartMap['is_show'] = 1;
                break;
            case 'buynow':
                $cartMap['is_show'] = 0;
                break;
        }

        $cartDb = new Cart();
        $cartData = $cartDb::all(function ($query) use ($cartMap) {
            $query
                ->with('goods,goodsSpecItem,goodsSpecImage')
                ->where($cartMap)
                ->limit($cartMap['is_show'] == 0 ? 1 : 0)
                ->order(['cart_id' => 'desc']);
        });

        if (false === $cartData) {
            return $this->setError($cartDb->getError());
        }

        $catrSer = new CartSer();
        $orderGoods = $catrSer->checkCartGoodsList($cartData->toArray(), $isBuyNow, true);

        if ($isBuyNow && false === $orderGoods) {
            return $this->setError($catrSer->getError());
        }

        if (empty($orderGoods)) {
            return $this->setError('待结算商品不存在');
        }

        // 计算商品实际价格(订单确认可直接返回)
        unset($cartData);
        $this->cartData['goods_list'] = $orderGoods;
        $this->cartData['coupon_give_list'] = [];
        $this->cartData['card_use_list'] = [];
        $this->cartData['integral'] = ['usable' => 0, 'give' => 0];
        $this->cartData['order_price'] = [
            'pay_amount'     => 0, // 订单金额
            'goods_amount'   => 0, // 商品金额
            'total_amount'   => 0, // 应付金额
            'invoice_amount' => 0, // 开票税率
            'use_money'      => 0, // 余额抵扣
            'use_level'      => 0, // 会员抵扣
            'use_integral'   => 0, // 积分抵扣
            'use_coupon'     => 0, // 优惠劵抵扣
            'use_discount'   => 0, // 商品折扣抵扣
            'use_promotion'  => 0, // 订单促销抵扣
            'use_card'       => 0, // 购物卡抵扣
            'delivery_fee'   => 0, // 运费
            'delivery_dec'   => 0, // 减少的运费
        ];

        // 计算订单金额
        $this->dataParams = $data;
        $isSuccess = $this->calculatePrice($clientId);

        if ($isSuccess && !$isBuyNow) {
            return $this->cartData;
        }

        if ($isSuccess && $isBuyNow) {
            $result = $this->createOrder($clientId);
            if (false !== $result) {
                return $result;
            }
        }

        return false;
    }

    /**
     * 计算订单金额
     * @access private
     * @param  int $clientId 账号编号
     * @return bool
     */
    private function calculatePrice($clientId)
    {
        // 部分数据需要初始化
        $goodsNum = 0;
        $integral = [];                                 // 积分百分比记录
        $isWeight = $isItem = $isVolume = false;        // 某一个计量是否包邮
        $weightTotal = $itemTotal = $volumeTotal = 0;   // 所有计量总合数值
        $goodsIdList = array_unique(array_column($this->cartData['goods_list'], 'goods_id'));
        $region = !empty($this->dataParams['region_list']) ? $this->dataParams['region_list'] : [];

        // 获取商品折扣数据
        $discountDb = new DiscountGoods();
        $this->discountData = $discountDb->getDiscountGoodsInfo(['goods_id' => $goodsIdList]);

        if (false === $this->discountData) {
            return $this->setError($discountDb->getError());
        }

        foreach ($this->cartData['goods_list'] as $value) {
            // 获取商品价格
            $shopPrice = $value['goods']['shop_price'];
            $this->cartData['order_price']['goods_amount'] += $value['goods_num'] * $shopPrice;

            $discountPrice = $this->calculateDiscountGoods($value['goods_id'], $shopPrice);
            $this->cartData['order_price']['use_discount'] += $value['goods_num'] * $discountPrice;

            // 计算累计可抵扣积分
            $this->cartData['integral']['usable'] += $value['goods']['is_integral'];

            // 计算固定值赠送积分
            if (1 == $value['goods']['integral_type']) {
                $this->cartData['integral']['give'] += $value['goods']['give_integral'];
            }

            // 记录百分比赠送积分
            if (0 == $value['goods']['integral_type'] && $value['goods']['give_integral'] > 0) {
                $integral[] = $value['goods']['give_integral'];
            }

            // 是否包邮区分,并且累计各个计量数值
            if (!$isWeight && 0 == $value['goods']['measure_type']) {
                $weightTotal += $value['goods']['measure'];
                !$value['goods']['is_postage'] ?: $isWeight = true | $weightTotal = 0;
            }

            if (!$isItem && 1 == $value['goods']['measure_type']) {
                $itemTotal += $value['goods_num'];
                !$value['goods']['is_postage'] ?: $isItem = true | $itemTotal = 0;
            }

            if (!$isVolume && 2 == $value['goods']['measure_type']) {
                $volumeTotal += $value['goods']['measure'];
                !$value['goods']['is_postage'] ?: $isVolume = true | $volumeTotal = 0;
            }

            // 累计商品件数
            $goodsNum += $value['goods_num'];
        }

        // 计算商品折扣额
        $this->cartData['order_price']['pay_amount'] = $this->cartData['order_price']['goods_amount'];
        $this->cartData['order_price']['pay_amount'] -= $this->cartData['order_price']['use_discount'];

        // 计算优惠劵折扣额
        $giveCheck['goods_id'] = $goodsIdList;
        $giveCheck['pay_amount'] = $this->cartData['order_price']['pay_amount'];

        $couponGiveDb = new CouponGive();
        $couponGiveData = $couponGiveDb->getCouponGiveSelect($giveCheck);

        if (false !== $couponGiveData) {
            $this->cartData['coupon_give_list'] = $couponGiveData;
        }

        if (!empty($this->dataParams['coupon_give_id']) || !empty($this->dataParams['coupon_exchange_code'])) {
            if (!empty($this->dataParams['coupon_give_id'])) {
                $giveCheck['coupon_give_id'] = $this->dataParams['coupon_give_id'];
            }

            if (!empty($this->dataParams['coupon_exchange_code'])) {
                $giveCheck['exchange_code'] = $this->dataParams['coupon_exchange_code'];
            }

            $couponGiveData = $couponGiveDb->getCouponGiveCheck($giveCheck);
            if (false === $couponGiveData) {
                return $this->setError($couponGiveDb->getError());
            }

            $this->cartData['order_price']['use_coupon'] = $couponGiveData['get_coupon']['money'];
            $this->cartData['order_price']['pay_amount'] -= $couponGiveData['get_coupon']['money'];
        }

        // 计算会员折扣额
        $userDb = new User();
        $userData = $userDb->getUserItem(['client_id' => $clientId]);

        if (!$userData) {
            return $this->setError($userDb->getError());
        }

        $userLevel = $this->cartData['order_price']['pay_amount'] * ($userData['get_user_level']['discount'] / 100);
        $this->cartData['order_price']['use_level'] = $this->cartData['order_price']['pay_amount'] - $userLevel;
        $this->cartData['order_price']['pay_amount'] -= $this->cartData['order_price']['use_level'];

        // 计算实际运费及优惠额结算
        if ($weightTotal > 0 || $itemTotal > 0 || $volumeTotal > 0) {
            $deliveryData['delivery_id'] = isset($this->dataParams['delivery_id']) ? $this->dataParams['delivery_id'] : 0;
            $deliveryData['weight_total'] = $weightTotal;
            $deliveryData['item_total'] = $itemTotal;
            $deliveryData['volume_total'] = $volumeTotal;

            // 反向取区、市、省中某个不为空的值
            $regionID = array_reverse($region);
            foreach ($regionID as $value) {
                if (!empty($value)) {
                    $deliveryData['region_id'] = $value;
                    break;
                }
            }

            if ($deliveryData['delivery_id'] > 0 && !empty($deliveryData['region_id'])) {
                $deliveryDb = new Delivery();
                $this->cartData['order_price']['delivery_fee'] = $deliveryDb->getDeliveryFreight($deliveryData)['delivery_fee'];

                if (false === $this->cartData['order_price']['delivery_fee']) {
                    return $this->setError($deliveryDb->getError());
                }
            }
        }

        // 满多少金额减多少运费计算
        if ($this->cartData['order_price']['delivery_fee'] > 0 && Config::get('dec_status.value', 'delivery') != 0) {
            if ($this->cartData['order_price']['goods_amount'] >= Config::get('quota.value', 'delivery')) {
                $isDec = true;
                $decExclude = json_decode(Config::get('dec_exclude.value', 'delivery'), true);

                foreach ($region as $value) {
                    if (in_array($value, $decExclude)) {
                        $isDec = false;
                        break;
                    }
                }

                if (true === $isDec) {
                    $this->cartData['order_price']['delivery_dec'] += Config::get('dec_money.value', 'delivery');
                    $this->cartData['order_price']['delivery_fee'] -= $this->cartData['order_price']['delivery_dec'];
                    $this->cartData['order_price']['delivery_fee'] > 0 ?: $this->cartData['order_price']['delivery_fee'] = 0;
                }
            }
        }

        // 满多少金额免运费计算
        if ($this->cartData['order_price']['delivery_fee'] > 0 && Config::get('money_status.value', 'delivery') != 0) {
            if ($this->cartData['order_price']['goods_amount'] >= Config::get('money.value', 'delivery')) {
                $isFree = true;
                $moneyExclude = json_decode(Config::get('money_exclude.value', 'delivery'), true);

                foreach ($region as $value) {
                    if (in_array($value, $moneyExclude)) {
                        $isFree = false;
                        break;
                    }
                }

                if (true === $isFree) {
                    $this->cartData['order_price']['delivery_dec'] += $this->cartData['order_price']['delivery_fee'];
                    $this->cartData['order_price']['delivery_fee'] = 0;
                }
            }
        }

        // 满多少件免运费计算
        if ($this->cartData['order_price']['delivery_fee'] > 0 && Config::get('number_status.value', 'delivery') != 0) {
            if ($goodsNum >= Config::get('number.value', 'delivery')) {
                $isNumber = true;
                $numberExclude = json_decode(Config::get('number_exclude.value', 'delivery'), true);

                foreach ($region as $value) {
                    if (in_array($value, $numberExclude)) {
                        $isNumber = false;
                        break;
                    }
                }

                if (true === $isNumber) {
                    $this->cartData['order_price']['delivery_dec'] += $this->cartData['order_price']['delivery_fee'];
                    $this->cartData['order_price']['delivery_fee'] = 0;
                }
            }
        }

        // 计算订单折扣额
        $promotionDb = new Promotion();
        $promotionData = $promotionDb->getPromotionActive();

        if (false === $promotionData) {
            return $this->setError($promotionDb->getError());
        }

        if (isset($promotionData['promotion_item'])) {
            foreach ($promotionData['promotion_item'] as $value) {
                if ($this->cartData['order_price']['pay_amount'] >= $value['quota']) {
                    $usePromotion = 0;
                    foreach ($value['settings'] as $item) {
                        switch ($item['type']) {
                            case 0: // 减价
                                $usePromotion += $item['value'];
                                break;
                            case 1: // 折扣
                                $price = $this->cartData['order_price']['pay_amount'] - $usePromotion;
                                $usePromotion += $price - ($price * ($item['value'] / 100));
                                break;
                            case 2: // 免邮
                                $this->cartData['order_price']['delivery_dec'] += $this->cartData['order_price']['delivery_fee'];
                                $this->cartData['order_price']['delivery_fee'] = 0;
                                break;
                            case 3: // 送积分
                                $this->cartData['integral']['give'] += $item['value'];
                                break;
                            case 4: // 送优惠劵
                                $this->orderData['give_coupon'][] = (int)$item['value'];
                                break;
                        }
                    }

                    $this->cartData['order_price']['use_promotion'] = $usePromotion;
                    $this->cartData['order_price']['pay_amount'] -= $usePromotion;
                    break;
                }
            }
        }

        // 小计应付金额
        $totalAmount = $this->cartData['order_price']['pay_amount'] + $this->cartData['order_price']['delivery_fee'];
        $this->orderData['integral_pct'] = Config::get('integral.value', 'system_shopping');

        // 计算余额抵扣额
        if (!empty($this->dataParams['use_money'])) {
            if (bccomp($userData['get_user_money']['balance'], $this->dataParams['use_money'], 2) === -1) {
                return $this->setError('可用余额不足');
            }

            $useMoney = $this->dataParams['use_money'] > $totalAmount ? $totalAmount : $this->dataParams['use_money'];
            $totalAmount -= $useMoney;
            $this->cartData['order_price']['use_money'] = $useMoney;
        }

        // 计算积分抵扣额
        if (!empty($this->dataParams['use_integral'])) {
            if (Config::get('integral.value', 'system_shopping') <= 0) {
                return $this->setError('积分支付已停用');
            }

            if (bccomp($userData['get_user_money']['points'], $this->dataParams['use_integral'], 2) === -1) {
                return $this->setError('可用积分不足');
            }

            if (bccomp($this->dataParams['use_integral'], $this->cartData['integral']['usable'], 2) === 1) {
                return $this->setError(sprintf('该笔订单最多可抵扣%d积分', $this->cartData['integral']['usable']));
            }

            // 将积分换算成等额币值
            $useIntegral = $this->dataParams['use_integral'] / $this->orderData['integral_pct'];
            $useIntegral <= $totalAmount ?: $useIntegral = $totalAmount;
            $totalAmount -= $useIntegral;
            $this->cartData['order_price']['use_integral'] = $useIntegral;
        }

        // 计算购物卡使用抵扣额
        $cardCheck['goods_id'] = $goodsIdList;
        $cardCheck['money'] = !empty($this->dataParams['use_card']) ? $this->dataParams['use_card'] : 0;
        $cardCheck['number'] = !empty($this->dataParams['card_number']) ? $this->dataParams['card_number'] : '';

        $cardUseDb = new CardUse();
        $cardUseData = $cardUseDb->getCardUseSelect($cardCheck);

        if (false !== $cardUseData) {
            $this->cartData['card_use_list'] = $cardUseData;
        }

        if (!empty($this->dataParams['use_card']) && !empty($this->dataParams['card_number'])) {
            if (!$cardUseDb->getCardUseCheck($cardCheck)) {
                return $this->setError($cardUseDb->getError());
            }

            $useCard = $this->dataParams['use_card'] > $totalAmount ? $totalAmount : $this->dataParams['use_card'];
            $totalAmount -= $useCard;
            $this->cartData['order_price']['use_card'] = $useCard;
        }

        // 计算发票税率
        $taxRate = Config::get('invoice.value', 'system_shopping');
        if (!empty($this->dataParams['invoice_type']) && $taxRate > 0) {
            $invoice = $this->cartData['order_price']['pay_amount'] * ($taxRate / 100);
            $this->cartData['order_price']['invoice_amount'] = $invoice;
            $totalAmount += $invoice;
        }

        // 设置实际应付金额
        $this->cartData['order_price']['total_amount'] = $totalAmount;

        // 积分百分比计算
        if (!empty($integral)) {
            // 累计实际付款金额
            $moneyTotal = 0;
            $moneyTotal += $this->cartData['order_price']['use_money'];
            $moneyTotal += $this->cartData['order_price']['use_card'];
            $moneyTotal += $this->cartData['order_price']['total_amount'];

            $average = (array_sum($integral) / count($integral)) / 100;
            $this->cartData['integral']['give'] += (int)($moneyTotal * $average);
        }

        // 对所有数值进行四舍五入
        foreach ($this->cartData['order_price'] as &$value) {
            $value = round($value, 2);
        }

        unset($value);
        return true;
    }

    /**
     * 根据区域编号获取完整收货地址
     * @access private
     * @param  array $data 不为空则为外部数据,否则使用内部数据
     * @return string
     */
    private function getCompleteAddress($data = null)
    {
        if (!is_null($data)) {
            $this->dataParams = $data;
        }

        // 订单区域编号组合
        $country = isset($this->dataParams['country']) ? $this->dataParams['country'] : 0;
        $regionList = $this->dataParams['region_list'];

        // 判断完整收货地址是否需要包含国籍
        if (Config::get('is_country.value', 'system_shopping') != 0) {
            array_unshift($regionList, $country);
        }

        $regionDb = new Region();
        $completeAddress = $regionDb->getRegionName(['region_id' => $regionList]);

        // 如区域地址存在,则需要添加分隔符用于增加详细地址
        if ($completeAddress != '') {
            $completeAddress .= Config::get('spacer.value', 'system_shopping');
        }

        return $completeAddress;
    }

    /**
     * 写入订单数据至数据库
     * @access private
     * @param  int $clientId 账号编号
     * @return bool
     */
    private function addOrderData($clientId)
    {
        // 订单数据入库准备
        $orderData = [
            'order_no'         => $this->getOrderNo(),
            'user_id'          => $clientId,
            'source'           => $this->dataParams['source'],
            'pay_amount'       => $this->cartData['order_price']['pay_amount'],
            'goods_amount'     => $this->cartData['order_price']['goods_amount'],
            'total_amount'     => $this->cartData['order_price']['total_amount'],
            'use_money'        => $this->cartData['order_price']['use_money'],
            'use_level'        => $this->cartData['order_price']['use_level'],
            'use_integral'     => $this->cartData['order_price']['use_integral'],
            'use_coupon'       => $this->cartData['order_price']['use_coupon'],
            'use_discount'     => $this->cartData['order_price']['use_discount'],
            'use_promotion'    => $this->cartData['order_price']['use_promotion'],
            'use_card'         => $this->cartData['order_price']['use_card'],
            'delivery_fee'     => $this->cartData['order_price']['delivery_fee'],
            'delivery_id'      => $this->dataParams['delivery_id'],
            'card_number'      => isset($this->dataParams['card_number']) ? $this->dataParams['card_number'] : '',
            'consignee'        => $this->dataParams['consignee'],
            'country'          => isset($this->dataParams['country']) ? $this->dataParams['country'] : 0,
            'region_list'      => $this->dataParams['region_list'],
            'address'          => $this->dataParams['address'],
            'complete_address' => $this->getCompleteAddress() . $this->dataParams['address'],
            'zipcode'          => isset($this->dataParams['zipcode']) ? $this->dataParams['zipcode'] : '',
            'tel'              => isset($this->dataParams['tel']) ? $this->dataParams['tel'] : '',
            'mobile'           => $this->dataParams['mobile'],
            'buyer_remark'     => isset($this->dataParams['buyer_remark']) ? $this->dataParams['buyer_remark'] : '',
            'create_user_id'   => is_client_admin() ? get_client_id() : 0,
            'integral_pct'     => $this->orderData['integral_pct'],
            'give_integral'    => $this->cartData['integral']['give'],
            'give_coupon'      => !empty($this->orderData['give_coupon']) ? $this->orderData['give_coupon'] : [],
            'invoice_amount'   => $this->cartData['order_price']['invoice_amount'],
            'trade_status'     => 0,
            'delivery_status'  => 0,
            'payment_status'   => 0,
        ];

        // 发票数据处理
        if (!$this->setInvoiceData($orderData)) {
            return false;
        }

        if (!$this->allowField(true)->isUpdate(false)->save($orderData)) {
            return false;
        }

        return true;
    }

    /**
     * 验证并处理发票数据
     * @access private
     * @param  array $invoiceData 订单完整数据
     * @return bool
     */
    private function setInvoiceData(&$invoiceData)
    {
        $invoiceType = isset($this->dataParams['invoice_type']) ? $this->dataParams['invoice_type'] : 0;
        if (2 == $invoiceType && empty($this->dataParams['invoice_title'])) {
            return $this->setError('发票抬头必须填写');
        }

        switch ($invoiceType) {
            case 1:
                $invoiceData['invoice_title'] = '个人';
                $invoiceData['tax_number'] = '';
                break;

            case 2:
                $invoiceData['invoice_title'] = $this->dataParams['invoice_title'];
                $invoiceData['tax_number'] = $this->dataParams['tax_number'];
                break;

            default:
                unset($invoiceData['invoice_title'], $invoiceData['tax_number']);
        }

        $invoiceData['invoice_type'] = $invoiceType;
        return true;
    }

    /**
     * 写入订单商品数据至数据库
     * @access private
     * @return bool
     */
    private function addOrderGoodsData()
    {
        $goodsData = [];
        $orderId = $this->getAttr('order_id');
        $orderNo = $this->getAttr('order_no');
        $userId = $this->getAttr('user_id');

        foreach ($this->cartData['goods_list'] as $value) {
            $goodsData[] = [
                'order_id'     => $orderId,
                'order_no'     => $orderNo,
                'user_id'      => $userId,
                'goods_name'   => $value['goods']['name'],
                'goods_id'     => $value['goods']['goods_id'],
                'goods_image'  => $value['goods']['goods_image'],
                'goods_code'   => $value['goods']['goods_code'],
                'goods_sku'    => $value['goods']['goods_sku'],
                'bar_code'     => $value['goods']['bar_code'],
                'key_name'     => $value['key_name'],
                'key_value'    => $value['key_value'],
                'market_price' => $value['goods']['market_price'],
                'shop_price'   => $value['goods']['shop_price'],
                'qty'          => $value['goods_num'],
            ];
        }

        $orderDb = new OrderGoods();
        if (false === $orderDb->insertAll($goodsData)) {
            return $this->setError($orderDb->getError());
        }

        return true;
    }

    /**
     * 添加订单日志
     * @access public
     * @param  array  $orderData 订单数据
     * @param  string $comment   备注
     * @param  string $desc      描述
     * @return bool
     */
    public function addOrderLog($orderData, $comment, $desc)
    {
        $data = [
            'order_id'        => $orderData['order_id'],
            'order_no'        => $orderData['order_no'],
            'trade_status'    => $orderData['trade_status'],
            'delivery_status' => $orderData['delivery_status'],
            'payment_status'  => $orderData['payment_status'],
            'comment'         => $comment,
            'description'     => $desc,
        ];

        $orderLogDb = new OrderLog();
        if (!$orderLogDb->addOrderItem($data)) {
            return $this->setError($orderLogDb->getError());
        }

        return true;
    }

    /**
     * 对应商品调整(库存,销量)
     * @access private
     * @return bool
     * @throws
     */
    private function setGoodsStoreQty()
    {
        foreach ($this->cartData['goods_list'] as $value) {
            // 规格非空则需要减规格库存
            if (!empty($value['key_name'])) {
                $map['goods_id'] = ['eq', $value['goods_id']];
                $map['key_name'] = ['eq', $value['key_name']];
                SpecGoods::where($map)->setDec('store_qty', $value['goods_num']);
            }

            // 减商品库存,增商品销量
            Goods::where(['goods_id' => ['eq', $value['goods_id']]])
                ->dec('store_qty', $value['goods_num'])
                ->inc('sales_sum', $value['goods_num'])
                ->update();
        }

        return true;
    }

    /**
     * 调整账号相关数据(余额,积分,购物卡使用,优惠劵)
     * @access private
     * @param  int $clientId 账号编号
     * @return bool
     */
    private function setUserData($clientId)
    {
        $txDb = new Transaction();
        $moneyDb = new UserMoney();

        // 交易结算日志
        $txData = [
            'user_id'    => $clientId,
            'type'       => $txDb::TRANSACTION_EXPENDITURE,
            'source_no'  => $this->getAttr('order_no'),
            'remark'     => '创建订单',
            'to_payment' => Payment::PAYMENT_CODE_USER,
        ];

        // 减少可用余额
        if ($this->cartData['order_price']['use_money'] > 0) {
            if (!$moneyDb->setBalance(-$this->cartData['order_price']['use_money'], $clientId)) {
                return $this->setError($moneyDb->getError());
            }

            // 补齐余额交易结算数据
            $txData['amount'] = $this->cartData['order_price']['use_money'];
            $txData['balance'] = $moneyDb->where(['user_id' => ['eq', $clientId]])->value('balance');
            $txData['module'] = 'money';

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 减少可用积分
        if ($this->cartData['order_price']['use_integral'] > 0) {
            $integral = $this->cartData['order_price']['use_integral'] * $this->orderData['integral_pct'];
            if (!$moneyDb->setPoints(-$integral, $clientId)) {
                return $this->setError($moneyDb->getError());
            }

            // 补齐积分交易结算数据
            $txData['amount'] = $integral;
            $txData['balance'] = $moneyDb->where(['user_id' => ['eq', $clientId]])->value('points');
            $txData['module'] = 'points';

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 减少购物卡使用可用余额
        if ($this->cartData['order_price']['use_card'] > 0) {
            $map['user_id'] = $clientId;
            $map['number'] = $this->dataParams['card_number'];

            $cardUseDb = new CardUse();
            if (!$cardUseDb->decCardUseMoney($map['number'], $this->cartData['order_price']['use_card'], $clientId)) {
                return $this->setError($cardUseDb->getError());
            }

            // 补齐购物卡使用交易结算数据
            $txData['amount'] = $this->cartData['order_price']['use_card'];
            $txData['balance'] = $cardUseDb->where($map)->value('money');
            $txData['module'] = 'card';
            $txData['to_payment'] = Payment::PAYMENT_CODE_CARD;
            $txData['card_number'] = $map['number'];

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 使用优惠劵
        if (!empty($this->dataParams['coupon_give_id']) || !empty($this->dataParams['coupon_exchange_code'])) {
            $couponDb = new CouponGive();
            $couponData['order_id'] = $this->getAttr('order_id');
            $couponData['order_no'] = $this->getAttr('order_no');

            if (!empty($this->dataParams['coupon_give_id'])) {
                $couponData['coupon_give_id'] = $this->dataParams['coupon_give_id'];
            }

            if (!empty($this->dataParams['coupon_exchange_code'])) {
                $couponData['exchange_code'] = $this->dataParams['coupon_exchange_code'];
            }

            if (!$couponDb->useCouponItem($couponData)) {
                return $this->setError($couponDb->getError());
            }
        }

        return true;
    }

    /**
     * 删除购物车商品
     * @access private
     * @param  int $clientId 账号编号
     * @return bool
     */
    private function delCartGoodsList($clientId)
    {
        $cartId = array_column($this->cartData['goods_list'], 'cart_id');
        if (false === Cart::where(['user_id' => ['eq', $clientId], 'cart_id' => ['in', $cartId]])->delete()) {
            return false;
        }

        return true;
    }

    /**
     * 创建订单
     * @access private
     * @param  int $clientId 账号编号
     * @return mixed
     * @throws
     */
    private function createOrder($clientId)
    {
        if (!$this->validateData($this->dataParams, 'Order.create')) {
            return false;
        }

        // 开启事务
        self::startTrans();

        try {
            // 添加订单主数据
            if (!$this->addOrderData($clientId)) {
                throw new \Exception($this->getError());
            }

            // 添加订单商品数据
            if (!$this->addOrderGoodsData()) {
                throw new \Exception($this->getError());
            }

            // 对应商品调整(库存,销量)
            if (!$this->setGoodsStoreQty()) {
                throw new \Exception($this->getError());
            }

            // 添加订单日志
            if (!$this->addOrderLog($this->toArray(), '提交订单成功', '提交订单')) {
                throw new \Exception($this->getError());
            }

            // 调整账号相关数据(余额,积分,购物卡使用,优惠劵)
            if (!$this->setUserData($clientId)) {
                throw new \Exception($this->getError());
            }

            // 删除购物车商品
            if (!$this->delCartGoodsList($clientId)) {
                throw new \Exception($this->getError());
            }

            self::commit();
            return $this->hidden(['order_id'])->toArray();
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 检测订单是否可设置为已支付状态
     * @access public
     * @param  array $data 外部数据
     * @return object|false
     * @throws
     */
    public function isPaymentStatus($data)
    {
        if (!$this->validateData($data, 'Order.is_payment')) {
            return false;
        }

        // 获取订单数据
        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['eq', 0];

            $query->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('trade_status') !== 0) {
            return $this->setError('订单不可支付');
        }

        if ($result->getAttr('payment_status') === 1) {
            return $this->setError('订单已完成支付');
        }

        return $result;
    }

    /**
     * 调整订单应付金额
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function changePriceOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.change_price')) {
            return false;
        }

        // 搜索条件
        $map['order_no'] = ['eq', $data['order_no']];
        $map['is_delete'] = ['eq', 0];
        is_client_admin() ?: $map['user_id'] = ['eq', 0];

        // 获取订单数据
        $result = $this->where($map)->find();
        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('trade_status') !== 0 || $result->getAttr('payment_status') !== 0) {
            return $this->setError('订单状态已不允许调整价格');
        }

        if (!empty($data['total_amount'])) {
            $totalAmount = $result->getAttr('total_amount');
            if (bcadd($totalAmount, $data['total_amount'], 2) < 0) {
                return $this->setError('修改额度最多可减 ' . $totalAmount);
            }

            $result->setAttr('total_amount', $totalAmount + $data['total_amount']);
            $result->setAttr('adjustment', $result->getAttr('adjustment') + $data['total_amount']);
        }

        // 开启事务
        self::startTrans();

        try {
            if (!empty($data['total_amount'])) {
                // 调整订单应付金额
                if (false === $result->save()) {
                    throw new \Exception($this->getError());
                }

                // 写入订单操作日志
                $info = sprintf('应付金额调整：%s%.2f', $data['total_amount'] > 0 ? '+' : '', $data['total_amount']);
                if (!$this->addOrderLog($result->toArray(), $info, '金额调整')) {
                    throw new \Exception($this->getError());
                }
            }

            self::commit();
            return [
                'pay_amount'     => $result->getAttr('pay_amount'),
                'goods_amount'   => $result->getAttr('goods_amount'),
                'total_amount'   => $result->getAttr('total_amount'),
                'use_money'      => $result->getAttr('use_money'),
                'use_level'      => $result->getAttr('use_level'),
                'use_integral'   => $result->getAttr('use_integral'),
                'use_coupon'     => $result->getAttr('use_coupon'),
                'use_discount'   => $result->getAttr('use_discount'),
                'use_promotion'  => $result->getAttr('use_promotion'),
                'use_card'       => $result->getAttr('use_card'),
                'delivery_fee'   => $result->getAttr('delivery_fee'),
                'invoice_amount' => $result->getAttr('invoice_amount'),
                'adjustment'     => $result->getAttr('adjustment'),
            ];
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 订单取消时退回商品数据(库存,销量)
     * @access private
     * @return bool
     * @throws
     */
    private function returnGoodsStoreQty()
    {
        // 搜索条件
        $map['order_no'] = ['eq', $this->orderData['order_no']];

        $orderGoodsDb = new OrderGoods();
        $orderData = $orderGoodsDb->field('goods_id,key_name,qty')->where($map)->select()->toArray();

        // 取消订单后需要将订单商品状态设为取消
        if (false === $orderGoodsDb->isUpdate(true)->save(['status' => 3], $map)) {
            return $this->setError($orderGoodsDb->getError());
        }

        foreach ($orderData as $value) {
            // 规格非空则需要加规格库存
            if (!empty($value['key_name'])) {
                $mapSpec['goods_id'] = ['eq', $value['goods_id']];
                $mapSpec['key_name'] = ['eq', $value['key_name']];
                SpecGoods::where($mapSpec)->setInc('store_qty', $value['qty']);
            }

            // 加商品库存,减商品销量
            $mapGoods['goods_id'] = ['eq', $value['goods_id']];
            Goods::where($mapGoods)->inc('store_qty', $value['qty'])->dec('sales_sum', $value['qty'])->update();
        }

        return true;
    }

    /**
     * 退回账号相关数据(余额,积分,购物卡使用,优惠劵)
     * @access private
     * @return bool
     * @throws
     */
    private function returnUserData()
    {
        $txDb = new Transaction();
        $moneyDb = new UserMoney();

        // 交易结算日志
        $txData = [
            'user_id'    => $this->orderData['user_id'],
            'type'       => $txDb::TRANSACTION_INCOME,
            'source_no'  => $this->orderData['order_no'],
            'remark'     => '取消订单',
            'to_payment' => Payment::PAYMENT_CODE_USER,
        ];

        // 增加可用余额
        if ($this->orderData['use_money'] > 0) {
            if (!$moneyDb->setBalance($this->orderData['use_money'], $this->orderData['user_id'])) {
                return $this->setError($moneyDb->getError());
            }

            // 补齐余额交易结算数据
            $txData['amount'] = $this->orderData['use_money'];
            $txData['balance'] = $moneyDb->where(['user_id' => ['eq', $this->orderData['user_id']]])->value('balance');
            $txData['module'] = 'money';

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 增加可用积分
        if ($this->orderData['use_integral'] > 0) {
            $integral = $this->orderData['use_integral'] * $this->orderData['integral_pct'];
            if (!$moneyDb->setPoints($integral, $this->orderData['user_id'])) {
                return $this->setError($moneyDb->getError());
            }

            // 补齐积分交易结算数据
            $txData['amount'] = $integral;
            $txData['balance'] = $moneyDb->where(['user_id' => ['eq', $this->orderData['user_id']]])->value('points');
            $txData['module'] = 'points';

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 增加购物卡使用可用余额
        if ($this->orderData['use_card'] > 0) {
            $map['user_id'] = $this->orderData['user_id'];
            $map['number'] = $this->orderData['card_number'];

            $cardUseDb = new CardUse();
            if (!$cardUseDb->incCardUseMoney($map['number'], $this->orderData['use_card'], $map['user_id'])) {
                return $this->setError($cardUseDb->getError());
            }

            // 补齐购物卡使用交易结算数据
            $txData['amount'] = $this->orderData['use_card'];
            $txData['balance'] = $cardUseDb->where($map)->value('money');
            $txData['module'] = 'card';
            $txData['to_payment'] = Payment::PAYMENT_CODE_CARD;
            $txData['card_number'] = $map['number'];

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        // 退回优惠劵
        if ($this->orderData['use_coupon'] > 0) {
            $couponMap['user_id'] = ['eq', $this->orderData['user_id']];
            $couponMap['order_id'] = ['eq', $this->orderData['order_id']];

            $couponDb = new CouponGive();
            if (false === $couponDb->save(['order_id' => 0, 'use_time' => 0], $couponMap)) {
                return $this->setError($couponDb->getError());
            }

            $mapCoupon['coupon_id'] = ['eq', $couponDb->where($couponMap)->value('coupon_id', 0, true)];
            Coupon::where($mapCoupon)->setDec('use_num');
        }

        return true;
    }

    /**
     * 取消一个订单
     * @access public
     * @param  array $data 外部数据
     * @return mixed
     * @throws
     */
    public function cancelOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.cancel')) {
            return false;
        }

        // 获取订单数据
        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['eq', 0];
            is_client_admin() ?: $map['user_id'] = ['eq', get_client_id()];

            $query->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('trade_status') > 1) {
            return $this->setError('订单状态已不允许取消');
        }

        // 开启事务
        self::startTrans();

        try {
            // 修改订单状态
            $saveData = ['trade_status' => 4, 'payment_status' => 0];
            if (false === $result->save($saveData)) {
                throw new \Exception($this->getError());
            }

            // 获取订单数据
            $this->orderData = $result->toArray();

            // 写入订单操作日志
            if (!$this->addOrderLog($this->orderData, '订单已取消', '取消订单')) {
                throw new \Exception($this->getError());
            }

            // 退回商品库存及销量
            if (!$this->returnGoodsStoreQty()) {
                throw new \Exception($this->getError());
            }

            // 返回账号相关数据(余额,积分,优惠劵)
            if (!$this->returnUserData()) {
                throw new \Exception($this->getError());
            }

            // 处理订单支付金额原路退回(该条件等同于检测"payment_status === 1")
            if ($this->orderData['total_amount'] > 0 && !empty($this->orderData['payment_no'])) {
                $refundDb = new OrderRefund();
                if (!$refundDb->refundOrderPayment($this->orderData)) {
                    throw new \Exception($refundDb->getError());
                }
            }

            self::commit();
            return $saveData;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 未付款订单超时自动取消
     * @access public
     * @return bool
     */
    public function timeoutOrderCancel()
    {
        // 设置脚本超时时间
        $seconds = 5 * 60;
        $max = ini_get('max_execution_time');

        if ($max != 0 && $seconds > $max) {
            ini_get('safe_mode') ?: @set_time_limit($seconds);
        }

        // 搜索条件
        $map['trade_status'] = ['eq', 0];
        $map['payment_status'] = ['eq', 0];
        $map['create_time'] = ['elt', time() - (Config::get('timeout.value', 'system_shopping') * 60)];
        $map['is_delete'] = ['eq', 0];

        self::where($map)->chunk(100, function ($order) {
            foreach ($order as $value) {
                // 应付金额为0时不需要关闭
                if (bccomp($value->getAttr('total_amount'), 0, 2) === 0) {
                    continue;
                }

                // 开启事务
                $value::startTrans();

                try {
                    // 修改订单状态
                    if (false === $value->save(['trade_status' => 4])) {
                        throw new \Exception($this->getError());
                    }

                    // 处理数据
                    unset($this->orderData);
                    $this->orderData = $value->toArray();

                    // 写入订单操作日志
                    if (!$this->addOrderLog($this->orderData, '付款超时订单已取消', '取消订单')) {
                        throw new \Exception($this->getError());
                    }

                    // 退回商品库存及销量
                    if (!$this->returnGoodsStoreQty()) {
                        throw new \Exception($this->getError());
                    }

                    // 返回账号相关数据(余额,积分,优惠劵)
                    if (!$this->returnUserData()) {
                        throw new \Exception($this->getError());
                    }

                    $value::commit();
                    continue;
                } catch (\Exception $e) {
                    $value::rollback();
                    continue;
                }
            }
        }, 'order_id');

        return true;
    }

    /**
     * 添加或编辑卖家备注
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function remarkOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.remark')) {
            return false;
        }

        $map['order_no'] = ['eq', $data['order_no']];
        $map['is_delete'] = ['eq', 0];
        is_client_admin() ?: $map['user_id'] = ['eq', 0];

        if (false !== $this->save(['sellers_remark' => $data['sellers_remark']], $map)) {
            return $this->toArray();
        }

        return false;
    }

    /**
     * 编辑一个订单
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function setOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.set')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['eq', 0];
            is_client_admin() ?: $map['user_id'] = ['eq', 0];

            $query->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('delivery_status') !== 0) {
            return $this->setError('订单已发货，不允许修改');
        }

        if ($result->getAttr('trade_status') > 1) {
            return $this->setError('订单状态已不允许修改');
        }

        // 设置允许修改的字段及避免无关字段
        unset($data['create_time'], $data['update_time']);
        $field = [
            'consignee', 'country', 'region_list', 'address', 'zipcode',
            'tel', 'mobile', 'invoice_title', 'tax_number', 'complete_address',
        ];

        // 处理完整收货地址
        $data['complete_address'] = $this->getCompleteAddress($data) . $data['address'];

        if (false !== $result->allowField($field)->save($data)) {
            $this->addOrderLog($result->toArray(), '订单部分信息已修改', '修改订单');
            return $result->hidden(['order_id','is_give'])->toArray();
        }

        return false;
    }

    /**
     * 获取一个订单
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function getOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.item')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            $with = ['getUser', 'getOrderGoods', 'getDelivery'];
            if (!empty($data['is_get_log'])) {
                $with['getOrderLog'] = function ($query) {
                    $query->order(['order_log_id' => 'desc']);
                };
            }

            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['neq', 2];

            if (!is_client_admin()) {
                $map['user_id'] = ['eq', get_client_id()];
                $query->field('sellers_remark', true);
            }

            $query->with($with)->where($map);
        });

        if (false !== $result) {
            // 隐藏不需要输出的字段
            $hidden = [
                'order_id',
                'is_give',
                'get_order_goods.order_id',
                'get_order_goods.order_no',
                'get_order_goods.user_id',
                'get_order_log.order_log_id',
                'get_order_log.order_id',
                'get_order_log.order_no',
                'get_user.user_id',
                'get_delivery.delivery_id',
            ];

            return is_null($result) ? null : $result->hidden($hidden)->toArray();
        }

        return false;
    }

    /**
     * 将订单放入回收站、还原或删除
     * @access public
     * @param  array $data 外部数据
     * @return bool
     * @throws
     */
    public function recycleOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.recycle')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['neq', 2];
            is_client_admin() ?: $map['user_id'] = ['eq', get_client_id()];

            $query->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('trade_status') !== 3 && $result->getAttr('trade_status') !== 4) {
            return $this->setError('该订单不允许此操作');
        }

        // 开启事务
        self::startTrans();

        try {
            // 修改订单状态
            if (false === $result->save(['is_delete' => $data['is_recycle']])) {
                throw new \Exception($this->getError());
            }

            // 写入订单操作日志
            switch ($data['is_recycle']) {
                case 0:
                    $info = '还原订单';
                    break;
                case 1:
                    $info = '删除订单';
                    break;
                case 2:
                    $info = '永久删除';
                    break;
                default:
                    $info = '异常操作';
            }

            if (!$this->addOrderLog($result->toArray(), $info, '订单回收站')) {
                throw new \Exception($this->getError());
            }

            self::commit();
            return true;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 订单批量设为配货状态
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function pickingOrderList($data)
    {
        if (!$this->validateData($data, 'Order.picking')) {
            return false;
        }

        $result = self::all(function ($query) use ($data) {
            $map['order_no'] = ['in', $data['order_no']];
            $map['is_delete'] = ['eq', 0];
            is_client_admin() ?: $map['user_id'] = ['eq', get_client_id()];

            $query->where($map);
        });

        if (false === $result) {
            return false;
        }

        // 开启事务
        self::startTrans();

        try {
            foreach ($result as $value) {
                $orderNo = $value->getAttr('order_no');
                if ($value->getAttr('payment_status') !== 1) {
                    return $this->setError(sprintf('订单号：%s 未付款不允许配货', $orderNo));
                }

                if ($value->getAttr('trade_status') !== 0 && $value->getAttr('trade_status') !== 1) {
                    return $this->setError(sprintf('订单号：%s 状态不允许配货', $orderNo));
                }

                // 修改订单状态
                $orderData['trade_status'] = $data['is_picking'];
                $orderData['picking_time'] = $data['is_picking'] == 1 ? time() : 0;

                if (false === $value->save($orderData)) {
                    throw new \Exception($this->getError());
                }

                // 写入订单操作日志
                $info = $data['is_picking'] == 1 ? '订单开始配货' : '订单取消配货';
                if (!$this->addOrderLog($value->toArray(), $info, '订单配货')) {
                    throw new \Exception($this->getError());
                }
            }

            self::commit();
            return ['trade_status' => $data['is_picking']];
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 订单设为发货状态
     * @access public
     * @param  array $data 外部数据
     * @return mixed
     * @throws
     */
    public function deliveryOrderItem($data)
    {
        if (!$this->validateData($data, 'Order.delivery')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['is_delete'] = ['eq', 0];
            is_client_admin() ?: $map['user_id'] = ['eq', 0];

            $query->with('getOrderGoods')->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        if ($result->getAttr('payment_status') !== 1) {
            return $this->setError('订单未付款不允许发货');
        }

        if ($result->getAttr('delivery_status') === 1) {
            return $this->setError('该笔订单已完成发货');
        }

        if ($result->getAttr('trade_status') !== 1 && $result->getAttr('trade_status') !== 2) {
            return $this->setError('订单状态不允许发货');
        }

        // 计算订单商品是否全部发货完成
        $completeCount = 0;
        $this->orderData = $result->toArray();

        foreach ($this->orderData['get_order_goods'] as $value) {
            // 累加已发货订单商品
            if ($value['status'] == 1 || $value['status'] == 3) {
                $completeCount++;
                continue;
            }

            // 累加本次可以转为发货状态的订单商品
            if ($value['status'] == 0 && in_array($value['order_goods_id'], $data['order_goods_id'])) {
                $completeCount++;
            }
        }

        // 是否完成发货或部分发货
        $isComplete = $completeCount == count($this->orderData['get_order_goods']);

        // 准备订单数据
        $data['trade_status'] = 2;
        $data['delivery_status'] = $isComplete ? 1 : 2;
        $data['delivery_time'] = time();
        unset($data['order_id']);

        // 开启事务
        self::startTrans();

        try {
            // 修改订单状态
            if (false === $result->allowField(true)->save($data)) {
                throw new \Exception($this->getError());
            }

            // 重新赋值订单数据
            unset($this->orderData);
            $this->orderData = $result->toArray();

            // 撤销售后服务单
            if (!$this->cancelOrderService('delivery')) {
                throw new \Exception($this->getError());
            }

            // 写入订单操作日志
            $info = $isComplete ? '订单完成发货' : '订单部分发货';
            if (!$this->addOrderLog($this->orderData, $info, '订单发货')) {
                throw new \Exception($this->getError());
            }

            // 添加一条配送记录
            if (!empty($data['logistic_code'])) {
                $deliveryData = [
                    'client_id'        => $this->orderData['user_id'],
                    'order_code'       => $this->orderData['order_no'],
                    'logistic_code'    => $data['logistic_code'],
                ];

                if (!is_empty_parm($data['delivery_id'])) {
                    $deliveryData['delivery_id'] = $data['delivery_id'];
                }

                if (!is_empty_parm($data['delivery_item_id'])) {
                    $deliveryData['delivery_item_id'] = $data['delivery_item_id'];
                }

                $deliveryDb = new DeliveryDist();
                if (false === $deliveryDb->addDeliveryDistItem($deliveryData)) {
                    throw new \Exception($deliveryDb->getError());
                }
            }

            // 订单商品发货设置
            $mapGoods['order_goods_id'] = ['in', $data['order_goods_id']];
            $mapGoods['order_no'] = ['eq', $data['order_no']];
            $mapGoods['status'] = ['eq', 0];

            $orderGoodsDb = new OrderGoods();
            if (false === $orderGoodsDb->isUpdate(true)->save(['status' => 1], $mapGoods)) {
                throw new \Exception($orderGoodsDb->getError());
            }

            // 数据返回前删除不必要字段
            $orderData = $this->orderData;
            unset($orderData['order_id']);
            unset($orderData['order_goods_id']);
            unset($orderData['logistic_code']);
            unset($orderData['get_order_goods']);

            self::commit();
            return $orderData;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 累计消费金额与赠送积分结算
     * @access private
     * @return bool
     */
    private function completeUserData()
    {
        // 调整账号累计消费金额
        $moneyDb = new UserMoney();
        if (!$moneyDb->incTotalMoney($this->orderData['pay_amount'], $this->orderData['user_id'])) {
            return $this->setError($moneyDb->getError());
        }

        // 账号增加订单赠送积分
        if ($this->orderData['give_integral'] > 0) {
            if (!$moneyDb->setPoints($this->orderData['give_integral'], $this->orderData['user_id'])) {
                return $this->setError($moneyDb->getError());
            }

            $txDb = new Transaction();
            $txData = [
                'user_id'    => $this->orderData['user_id'],
                'type'       => $txDb::TRANSACTION_INCOME,
                'amount'     => $this->orderData['give_integral'],
                'balance'    => $moneyDb->where(['user_id' => ['eq', $this->orderData['user_id']]])->value('points'),
                'source_no'  => $this->orderData['order_no'],
                'remark'     => '赠送积分',
                'module'     => 'points',
                'to_payment' => Payment::PAYMENT_CODE_USER,
            ];

            if (!$txDb->addTransactionItem($txData)) {
                return $this->setError($txDb->getError());
            }
        }

        return true;
    }

    /**
     * 赠送优惠劵结算
     * @access private
     * @param  object $orderDb 订单模型
     * @return bool
     */
    public function completeGiveCoupon(&$orderDb)
    {
        $data = [];
        $couponGiveDb = new CouponGive();

        foreach ($this->orderData['give_coupon'] as $item) {
            $couponGiveId = $couponGiveDb->giveCouponOrder($item, $this->orderData['user_id']);
            if (false !== $couponGiveId && !empty($couponGiveId)) {
                $data = array_merge($data, $couponGiveId);
            }
        }

        if (false !== $orderDb->isUpdate(true)->save(['give_coupon' => $data])) {
            return true;
        }

        return false;
    }

    /**
     * 订单商品变更为收货状态
     * @access private
     * @param  int $orderId 订单编号
     * @return bool
     */
    private function completeOrderGoods($orderId)
    {
        $map['order_id'] = ['eq', $orderId];
        $map['status'] = ['eq', 1];

        $orderGoodsDb = new OrderGoods();
        if (false === $orderGoodsDb->isUpdate(true)->save(['status' => 2], $map)) {
            return $this->setError($orderGoodsDb->getError());
        }

        return true;
    }

    /**
     * 撤销某订单号下的所有售后服务单
     * @access private
     * @param  string $type 撤销类型
     * @return bool
     */
    private function cancelOrderService($type)
    {
        $orderServiceDb = new OrderService();
        if (!$orderServiceDb->inCancelOrderService($this->orderData['order_no'], $type)) {
            return $this->setError($orderServiceDb->getError());
        }

        return true;
    }

    /**
     * 订单批量确认收货
     * @access public
     * @param  array $data 外部数据
     * @return mixed
     * @throws
     */
    public function completeOrderList($data)
    {
        if (!$this->validateData($data, 'Order.complete')) {
            return false;
        }

        $result = self::all(function ($query) use ($data) {
            $map['order_no'] = ['in', $data['order_no']];
            $map['is_delete'] = ['eq', 0];
            is_client_admin() ?: $map['user_id'] = ['eq', get_client_id()];

            $query->where($map);
        });

        if (false === $result) {
            return false;
        }

        // 开启事务
        self::startTrans();

        try {
            $saveData = [
                'trade_status'  => 3,
                'finished_time' => $this->formatDateTime(time(), $this->dateFormat),
            ];

            foreach ($result as $value) {
                $orderNo = $value->getAttr('order_no');
                if ($value->getAttr('delivery_status') !== 1) {
                    return $this->setError(sprintf('订单号：%s 未发货或未全部发货完成', $orderNo));
                }

                if ($value->getAttr('trade_status') === 3) {
                    return $this->setError(sprintf('订单号：%s 已完成确认收货', $orderNo));
                }

                if ($value->getAttr('trade_status') !== 2 || $value->getAttr('delivery_status') === 0) {
                    return $this->setError(sprintf('订单号：%s 状态不允许确认收货', $orderNo));
                }

                // 修改订单状态
                if (false === $value->save($saveData)) {
                    throw new \Exception($this->getError());
                }

                // 重新赋值订单数据
                unset($this->orderData);
                $this->orderData = $value->toArray();

                // 撤销售后服务单
                if (!$this->cancelOrderService('complete')) {
                    throw new \Exception($this->getError());
                }

                // 订单商品设为收货状态
                if (!$this->completeOrderGoods($this->orderData['order_id'])) {
                    throw new \Exception($this->getError());
                }

                // 写入订单操作日志
                if (!$this->addOrderLog($this->orderData, '确认收货，交易已完成', '确认收货')) {
                    throw new \Exception($this->getError());
                }

                // 结算累计消费金额,赠送积分,赠送优惠劵
                if ($this->orderData['is_give'] === 1) {
                    if (!$this->completeUserData()) {
                        throw new \Exception($this->getError());
                    }

                    if (!$this->completeGiveCoupon($value)) {
                        throw new \Exception($this->getError());
                    }
                }
            }

            self::commit();
            return $saveData;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 未确认收货订单超时自动完成
     * @access public
     * @return bool
     */
    public function timeoutOrderComplete()
    {
        // 设置脚本超时时间
        $seconds = 10 * 60;
        $max = ini_get('max_execution_time');

        if ($max != 0 && $seconds > $max) {
            ini_get('safe_mode') ?: @set_time_limit($seconds);
        }

        // 关联查询订单是否存在售后服务
        $with['getOrderGoods'] = function ($query) {
            $query->field('order_id')->where(['is_service' => ['eq', 1]]);
        };

        // 搜索条件
        $map['trade_status'] = ['eq', 2];
        $map['delivery_status'] = ['eq', 1];
        $map['delivery_time'] = ['elt', time() - Config::get('complete.value', 'system_shopping') * 86400];
        $map['is_delete'] = ['eq', 0];

        self::with($with)->where($map)->chunk(100, function ($order) {
            foreach ($order as $value) {
                // 订单存在售后服务则放弃确认收货
                if (!$value->get_order_goods->isEmpty()) {
                    continue;
                }

                // 开启事务
                $value::startTrans();

                try {
                    // 修改订单状态
                    if (false === $value->save(['trade_status' => 3, 'finished_time' => time()])) {
                        throw new \Exception($this->getError());
                    }

                    // 重新赋值订单数据
                    unset($this->orderData);
                    $this->orderData = $value->toArray();

                    // 订单商品设为收货状态
                    if (!$this->completeOrderGoods($this->orderData['order_id'])) {
                        throw new \Exception($this->getError());
                    }

                    // 写入订单操作日志
                    if (!$this->addOrderLog($this->orderData, '交易超时，自动确认收货', '确认收货')) {
                        throw new \Exception($this->getError());
                    }

                    // 结算累计消费金额,赠送积分,赠送优惠劵
                    if ($this->orderData['is_give'] === 1) {
                        if (!$this->completeUserData()) {
                            throw new \Exception($this->getError());
                        }

                        if (!$this->completeGiveCoupon($value)) {
                            throw new \Exception($this->getError());
                        }
                    }

                    $value::commit();
                    continue;
                } catch (\Exception $e) {
                    $value::rollback();
                    continue;
                }
            }
        }, 'delivery_time', 'desc');

        return true;
    }

    /**
     * 获取订单列表
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function getOrderList($data)
    {
        if (!$this->validateData($data, 'Order.list')) {
            return false;
        }

        // 搜索条件
        $map['is_delete'] = ['eq', 0];
        is_client_admin() ? $map['shop_id'] = ['eq', get_client_shop_id()] : $map['user_id'] = ['eq', get_client_id()];
        empty($data['consignee']) ?: $map['consignee'] = ['eq', $data['consignee']];
        empty($data['mobile']) ?: $map['mobile'] = ['eq', $data['mobile']];
        empty($data['payment_code']) ?: $map['payment_code'] = ['eq', $data['payment_code']];

        if (!empty($data['begin_time']) && !empty($data['end_time'])) {
            $map['create_time'] = ['between time', [$data['begin_time'], $data['end_time']]];
        }

        // 关联订单商品搜索条件
        empty($data['keywords']) ?: $mapGoods['order_no|goods_name'] = ['like', '%' . $data['keywords'] . '%'];

        // 不同的订单状态生成搜索条件
        switch (isset($data['status']) ? $data['status'] : 0) {
            case 1: // 未付款/待付款
                $map['trade_status'] = ['eq', 0];
                $map['payment_status'] = ['eq', 0];
                break;
            case 2: // 已付款
                $map['trade_status'] = ['eq', 0];
                $map['payment_status'] = ['eq', 1];
                break;
            case 3: // 待发货/配货中
                $map['trade_status'] = ['in', [1, 2]];
                $map['delivery_status'] = ['neq', 1];
                break;
            case 4: // 已发货/待收货
                $map['trade_status'] = ['eq', 2];
                $map['delivery_status'] = ['eq', 1];
                break;
            case 5: // 已完成/已收货
                $map['trade_status'] = ['eq', 3];
                $map['delivery_status'] = ['eq', 1];
                break;
            case 6: // 已取消
                $map['trade_status'] = ['eq', 4];
                break;
            case 7: // 待评价
                $map['trade_status'] = ['eq', 3];
                $mapGoods['is_comment'] = ['eq', 0];
                break;
            case 8: // 回收站
                $map['is_delete'] = ['eq', 1];
                break;
        }

        // 关联订单商品查询,返回订单编号
        if (!empty($mapGoods)) {
            is_client_admin() ?: $mapGoods['user_id'] = ['eq', get_client_id()];
            $orderId = OrderGoods::where($mapGoods)->column('order_id');
            $map['order_id'] = ['in', $orderId];
        }

        // 通过账号或昵称查询
        if (is_client_admin() && !empty($data['account'])) {
            $mapUser['username|nickname'] = ['eq', $data['account']];
            $userId = User::where($mapUser)->value('user_id', 0, true);
            $map['user_id'] = ['eq', $userId];
        }

        $totalResult = $this->where($map)->count();
        if ($totalResult <= 0) {
            return ['total_result' => 0];
        }

        $result = self::all(function ($query) use ($data, $map) {
            // 翻页页数
            $pageNo = isset($data['page_no']) ? $data['page_no'] : 1;

            // 每页条数
            $pageSize = isset($data['page_size']) ? $data['page_size'] : config('paginate.list_rows');

            // 排序方式
            $orderType = !empty($data['order_type']) ? $data['order_type'] : 'desc';

            // 排序的字段
            $orderField = !empty($data['order_field']) ? $data['order_field'] : 'order_id';

            // 过滤字段
            if (!is_client_admin()) {
                $query->field('sellers_remark', true);
            }

            // 查询数据
            $query
                ->with('getUser,getOrderGoods,getDelivery')
                ->where($map)
                ->order([$orderField => $orderType]);

            // 区分是否为数据导出
            if (empty($data['is_export'])) {
                $query->page($pageNo, $pageSize);
            } else {
                $query->whereTime('create_time', '>=', Time::daysAgo(90));
            }
        });

        if (false !== $result) {
            // 隐藏不需要输出的字段
            $hidden = [
                'order_id',
                'is_give',
                'get_order_goods.order_id',
                'get_order_goods.user_id',
                'get_user.user_id',
                'get_delivery.delivery_id',
            ];

            return ['items' => $result->hidden($hidden)->toArray(), 'total_result' => $totalResult];
        }

        return false;
    }

    /**
     * 获取订单各个状态合计数
     * @param  array $data 外部数据
     * @access public
     * @return array
     * @throws
     */
    public function getOrderStatusTotal($data)
    {
        // 准备基础数据
        $result = [
            'not_paid'    => 0, // 未付款/待付款
            'paid'        => 0, // 已付款
            'not_shipped' => 0, // 待发货/配货中
            'shipped'     => 0, // 已发货/待收货
            'not_comment' => 0, // 待评价
        ];

        if (!is_client_admin() && get_client_id() == 0) {
            return $result;
        }

        // 通用查询条件
        $map['is_delete'] = ['eq', 0];
        empty($data['consignee']) ?: $map['consignee'] = ['eq', $data['consignee']];
        empty($data['mobile']) ?: $map['mobile'] = ['eq', $data['mobile']];
        empty($data['payment_code']) ?: $map['payment_code'] = ['eq', $data['payment_code']];

        if (!empty($data['begin_time']) && !empty($data['end_time'])) {
            $map['create_time'] = ['between time', [$data['begin_time'], $data['end_time']]];
        }

        // 通过账号或昵称查询
        if (is_client_admin() && !empty($data['account'])) {
            $mapUser['username|nickname'] = ['eq', $data['account']];
            $userId = User::where($mapUser)->value('user_id', 0, true);
            $map['user_id'] = ['eq', $userId];
        }

        // 通过关键词查询
        if (!empty($data['keywords'])) {
            $mapKeywords['order_no|goods_name'] = ['like', '%' . $data['keywords'] . '%'];
            $idList = OrderGoods::where($mapKeywords)->column('order_id');
            $map['order_id'] = ['in', $idList];
        }

        $mapNotPaid['trade_status'] = ['eq', 0];
        $mapNotPaid['payment_status'] = ['eq', 0];
        $result['not_paid'] = $this->where($mapNotPaid)->where($map)->count();

        $mapPaid['trade_status'] = ['eq', 0];
        $mapPaid['payment_status'] = ['eq', 1];
        $result['paid'] = $this->where($mapPaid)->where($map)->count();

        $mapNotShipped['trade_status'] = ['in', [1, 2]];
        $mapNotShipped['delivery_status'] = ['neq', 1];
        $result['not_shipped'] = $this->where($mapNotShipped)->where($map)->count();

        $mapShipped['trade_status'] = ['eq', 2];
        $mapShipped['delivery_status'] = ['eq', 1];
        $result['shipped'] = $this->where($mapShipped)->where($map)->count();

        // 获取未评价订单商品
        is_client_admin() ?: $mapGoods['user_id'] = ['eq', get_client_id()];
        $mapGoods['is_comment'] = ['eq', 0];
        $mapGoods['status'] = ['eq', 2];
        $orderId = OrderGoods::where($mapGoods)->column('order_id');

        $mapNotComment['order_id'] = ['in', $orderId];
        $mapNotComment['trade_status'] = ['eq', 3];
        $result['not_comment'] = $this->where($mapNotComment)->where($map)->count();

        return $result;
    }

    /**
     * 再次购买与订单相同的商品
     * @access public
     * @param  array $data 外部数据
     * @return bool
     * @throws
     */
    public function buyagainOrderGoods($data)
    {
        if (!$this->validateData($data, 'Order.buy_again')) {
            return false;
        }

        // 获取关联订单商品列表
        $result = self::get(function ($query) use ($data) {
            $map['order_no'] = ['eq', $data['order_no']];
            $map['user_id'] = ['eq', get_client_id()];

            $query->with('getOrderGoods')->where($map);
        });

        if (!$result) {
            return is_null($result) ? $this->setError('订单不存在') : false;
        }

        // 组合数据成购物车结构
        $cartDb = new Cart();
        $cartData['cart_goods'] = [];
        $result = $result->toArray();

        foreach ($result['get_order_goods'] as $value) {
            $temp = $cartDb->checkCartGoods([
                'goods_id'   => $value['goods_id'],
                'goods_num'  => $value['qty'],
                'goods_spec' => !empty($value['key_name']) ? explode('_', $value['key_name']) : [],
            ]);

            if (false === $temp) {
                return $this->setError($cartDb->getError());
            }

            $cartData['cart_goods'][] = $temp;
        }

        if (false === $cartDb->addCartList($cartData)) {
            return $this->setError($cartDb->getError());
        }

        return true;
    }

    /**
     * 获取可评价或可追评的订单商品列表
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getOrderGoodsComment($data)
    {
        if (!$this->validateData($data, 'Order.comment')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            // 获取关联订单商品数据
            $goodsMap['is_comment'] = ['eq', $data['comment_type'] == 'comment' ? 0 : 1];
            $goodsMap['status'] = ['eq', 2];

            $with['getOrderGoods'] = function ($goodsDb) use ($goodsMap) {
                $goodsDb->field('order_no,user_id,is_comment,status', true)->where($goodsMap);
            };

            // 搜索订单数据
            $map['order_no'] = ['eq', $data['order_no']];
            $map['user_id'] = ['eq', get_client_id()];
            $map['trade_status'] = ['eq', 3];
            $map['is_delete'] = ['neq', 2];

            $query->with($with)->field('order_id,order_no')->where($map);
        });

        if (false === $result) {
            return false;
        }

        return is_null($result) ? null : $result->hidden(['order_id', 'get_order_goods.order_id'])->toArray();
    }
}
