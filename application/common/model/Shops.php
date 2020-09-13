<?php
/**
 * @copyright   Copyright (c) http://careyshop.cn All rights reserved.
 *
 * CareyShop    店铺模型
 *
 * @author      zxm <252404501@qq.com>
 * @date        2017/4/11
 */

namespace app\common\model;

use think\Cache;
use util\Http;
use think\helper\Str;

class Shops extends CareyShop
{
    /**
     * 店铺属性模型对象
     * @var object
     */
    private static $shopsAttr = null;

    /**
     * 店铺规格模型对象
     * @var object
     */
    private static $specShops = null;

    /**
     * 店铺规格图片模型对象
     * @var object
     */
    private static $specImage = null;

    /**
     * 是否需要自动写入时间戳
     * @var bool
     */
    protected $autoWriteTimestamp = true;

    /**
     * 只读属性
     * @var array
     */
    protected $readonly = [
        'shop_id'
    ];

    /**
     * 字段类型或者格式转换
     * @var array
     */
    protected $type = [
        'shop_id'          => 'integer',
        'sort'              => 'integer',
        'status'            => 'integer',
        'is_delete'         => 'integer',
    ];

    /**
     * hasMany cs_goods_attr
     * @access public
     * @return mixed
     */
    public function shopsAttrItem()
    {
        return $this->hasMany('ShopsAttr', 'shop_id');
    }

    /**
     * 初始化处理
     * @access protected
     * @return void
     */
    protected static function init()
    {
        !is_null(self::$shopsAttr) ?: self::$shopsAttr = new ShopsAttr();
    }

    /**
     * 通用全局查询条件
     * @access protected
     * @param  object $query 模型
     * @return void
     */
    protected function scopeGlobal($query)
    {
        $query->where(['status' => ['eq', 1], 'is_delete' => ['eq', 0], 'store_qty' => ['gt', 0]]);
    }

    /**
     * 产生随机10位的店铺号
     * @access private
     * @return string
     */
    private function setGoodsCode()
    {
        do {
            $goodsCode = 'CS' . rand_number(8);
        } while (self::checkUnique(['goods_code' => ['eq', $goodsCode]]));

        return $goodsCode;
    }

    /**
     * 检测店铺店铺货号是否唯一
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function uniqueGoodsCode($data)
    {
        if (!$this->validateData($data, 'Goods.unique')) {
            return false;
        }

        $map['goods_code'] = ['eq', $data['goods_code']];
        !isset($data['exclude_id']) ?: $map['goods_id'] = ['neq', $data['exclude_id']];

        if (self::checkUnique($map)) {
            return $this->setError('店铺货号已存在');
        }

        return true;
    }

    /**
     * 添加店铺附加属性与规格
     * @access private
     * @param  int   $goodsId 店铺编号
     * @param  array &$result 店铺自身数据集
     * @param  array $data    外部数据
     * @return bool
     */
    private function addGoodSubjoin($goodsId, &$result, $data)
    {
        // 检测规格是否存在自定义,存在则更新,并且返回会附带规格图集合
        \app\common\service\SpecGoods::validateSpecMenu($data);

        // 插入店铺属性列表
        if (!empty($data['attr_config'])) {
            $attrList = [];
            GoodsAttrConfig::updateAttrConfig($goodsId, $data['attr_config']);

            foreach ($data['attr_config'] as $value) {
                foreach ($value['get_attribute'] as $key => $item) {
                    if (!is_empty_parm($item['attr_value'])) {
                        $attr_value = is_array($item['attr_value'])
                            ? implode(' ', $item['attr_value'])
                            : $item['attr_value'];

                        $attrList[] = [
                            'goods_id'           => $goodsId,
                            'goods_attribute_id' => $item['goods_attribute_id'],
                            'parent_id'          => $item['parent_id'],
                            'is_important'       => $item['is_important'],
                            'attr_value'         => $attr_value,
                            'sort'               => $key,
                        ];
                    }
                }
            }

            if (!empty($attrList)) {
                if (false === self::$goodsAttr->addGoodsAttr($goodsId, $attrList)) {
                    return $this->setError(self::$goodsAttr->getError());
                }
            }
        }

        // 处理店铺规格配置
        if (!empty($data['spec_config'])) {
            SpecConfig::updateSpecConfig($goodsId, $data['spec_config']);
        }

        // 插入店铺规格组合列表
        if (!empty($data['spec_combo'])) {
            if (false === self::$specGoods->addGoodsSpec($goodsId, $data['spec_combo'])) {
                return $this->setError(self::$specGoods->getError());
            }

            // 计算实际店铺库存并更新
            $result['store_qty'] = (int)array_sum(array_column($data['spec_combo'], 'store_qty'));
            $this->where(['goods_id' => ['eq', $goodsId]])->setField('store_qty', $result['store_qty']);
        }

        // 插入店铺规格图片
        if (!empty($data['spec_image'])) {
            if (false === self::$specImage->addSpecImage($goodsId, $data['spec_image'])) {
                return $this->setError(self::$specImage->getError());
            }
        }

        return true;
    }

    /**
     * 添加一个店铺
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function addGoodsItem($data)
    {
        if (!$this->validateData($data, 'Goods')) {
            return false;
        }

        // 过滤无关字段及初始部分数据
        unset($data['goods_id'], $data['comment_sum'], $data['sales_sum'], $data['is_delete']);
        unset($data['create_time'], $data['update_time']);
        !empty($data['goods_code']) ?: $data['goods_code'] = $this->setGoodsCode();

        // 开启事务
        self::startTrans();

        try {
            // 写入主表
            if (!$this->allowField(true)->save($data)) {
                throw new \Exception($this->getError());
            }

            // 写入属性与规格
            $result = $this->toArray();
            if (!$this->addGoodSubjoin($this->getAttr('goods_id'), $result, $data)) {
                throw new \Exception($this->getError());
            }

            self::commit();
            Cache::clear('GoodsCategory');
            return $result;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 编辑一个店铺
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function setGoodsItem($data)
    {
        if (!$this->validateSetData($data, 'Goods.set')) {
            return false;
        }

        if (isset($data['goods_code'])) {
            $map['goods_id'] = ['neq', $data['goods_id']];
            $map['goods_code'] = ['eq', $data['goods_code']];

            if (self::checkUnique($map)) {
                return $this->setError('店铺货号已存在');
            }

            // 如果为空则产生一个随机货号
            !empty($data['goods_code']) ?: $data['goods_code'] = $this->setGoodsCode();
        }

        $map['goods_id'] = ['eq', $data['goods_id']];
        unset($map['goods_code']);

        // 开启事务
        self::startTrans();

        try {
            if (false === $this->allowField(true)->save($data, $map)) {
                throw new \Exception($this->getError());
            }

            if (!empty($data['attr_config'])) {
                if (false === self::$goodsAttr->where($map)->delete()) {
                    throw new \Exception(self::$goodsAttr->getError());
                }
            }

            if (!empty($data['spec_config'])) {
                if (false === self::$specImage->where($map)->delete()) {
                    throw new \Exception(self::$specImage->getError());
                }
            }

            if (!empty($data['spec_combo'])) {
                if (false === self::$specGoods->where($map)->delete()) {
                    throw new \Exception(self::$specGoods->getError());
                }
            }

            $result = $this->toArray();
            if (!$this->addGoodSubjoin($data['goods_id'], $result, $data)) {
                throw new \Exception($this->getError());
            }

            self::commit();
            return $result;
        } catch (\Exception $e) {
            self::rollback();
            return $this->setError($e->getMessage());
        }
    }

    /**
     * 获取一个店铺
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsItem($data)
    {
        if (!$this->validateData($data, 'Shops.item')) {
            return false;
        }

        $result = self::get(function ($query) use ($data) {
            $query->where(['shop_id' => ['eq', $data['shop_id']]]);
        });

        if (false !== $result) {
            return is_null($result) ? null : $result->toArray();
        }

        return false;
    }

    /**
     * 批量删除或恢复店铺(回收站)
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function delGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.del')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['is_delete' => $data['is_delete']], $map)) {
            Cache::clear('GoodsCategory');
            return true;
        }

        return false;
    }

    /**
     * 获取指定编号店铺的基础数据
     * @access public
     * @param  array $data 外部数据
     * @return array|bool
     */
    public function getGoodsSelect($data)
    {
        if (!$this->validateData($data, 'Goods.select')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        $field = 'goods_id,name,short_name,attachment,store_qty,sales_sum,status,is_delete';

        $order = [];
        $result = $this->where($map)->column($field, 'goods_id');

        // 根据传入顺序返回列表
        foreach ($data['goods_id'] as $value) {
            if (array_key_exists($value, $result)) {
                $order[] = $result[$value];
            }
        }

        return $order;
    }

    /**
     * 批量设置或关闭店铺可积分抵扣
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setIntegralGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.integral')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['is_integral' => $data['is_integral']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 批量设置店铺是否推荐
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setRecommendGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.recommend')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['is_recommend' => $data['is_recommend']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 批量设置店铺是否为新品
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setNewGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.new')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['is_new' => $data['is_new']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 批量设置店铺是否为热卖
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setHotGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.hot')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['is_hot' => $data['is_hot']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 批量设置店铺是否上下架
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setShelvesGoodsList($data)
    {
        if (!$this->validateData($data, 'Goods.shelves')) {
            return false;
        }

        $map['goods_id'] = ['in', $data['goods_id']];
        if (false !== $this->save(['status' => $data['status']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 获取指定店铺的属性列表
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsAttrList($data)
    {
        if (!$this->validateData($data, 'Goods.item')) {
            return false;
        }

        $result = GoodsAttr::all(function ($query) use ($data) {
            $query->where(['goods_id' => ['eq', $data['goods_id']]]);
        });

        if (false !== $result) {
            return $result->toArray();
        }

        return false;
    }

    /**
     * 获取指定店铺的规格组合列表
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsSpecList($data)
    {
        if (!$this->validateData($data, 'Goods.item')) {
            return false;
        }

        $result = SpecGoods::all(function ($query) use ($data) {
            $query->where(['goods_id' => ['eq', $data['goods_id']]]);
        });

        if (false !== $result) {
            return array_column($result->toArray(), null, 'key_name');
        }

        return false;
    }

    /**
     * 获取指定店铺的规格图
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsSpecImage($data)
    {
        if (!$this->validateData($data, 'Goods.item')) {
            return false;
        }

        $result = SpecImage::all(function ($query) use ($data) {
            $query->where(['goods_id' => ['eq', $data['goods_id']]]);
        });

        if (false !== $result) {
            return $result->toArray();
        }

        return false;
    }

    /**
     * 获取管理后台店铺列表
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsAdminList($data)
    {
        if (!$this->validateData($data, 'Goods.admin_list')) {
            return false;
        }

        // 获取店铺店铺分类Id,包括子分类
        $catIdList = [];
        if (isset($data['goods_category_id'])) {
            if (0 == $data['goods_category_id'] || '' == $data['goods_category_id']) {
                $catIdList[] = 0;
            } else {
                $goodsCat = GoodsCategory::getCategoryList($data['goods_category_id'], false, true);
                $catIdList = array_column((array)$goodsCat, 'goods_category_id');
            }
        }

        // 搜索条件
        $map = [];
        !isset($data['goods_id']) ?: $map['goods_id'] = ['in', $data['goods_id']];
        !isset($data['exclude_id']) ?: $map['goods_id'] = ['not in', $data['exclude_id']];
        empty($data['goods_code']) ?: $map['goods_code|goods_spu|goods_sku|bar_code'] = ['eq', $data['goods_code']];
        empty($data['brand_id']) ?: $map['brand_id'] = ['in', $data['brand_id']];
        empty($data['store_qty']) ?: $map['store_qty'] = ['between', $data['store_qty']];
        empty($catIdList) ?: $map['goods_category_id'] = ['in', $catIdList];
        is_empty_parm($data['is_postage']) ?: $map['is_postage'] = ['eq', $data['is_postage']];
        is_empty_parm($data['is_integral']) ?: $map['is_integral'] = ['gt', 0];
        is_empty_parm($data['is_recommend']) ?: $map['is_recommend'] = ['eq', $data['is_recommend']];
        is_empty_parm($data['is_new']) ?: $map['is_new'] = ['eq', $data['is_new']];
        is_empty_parm($data['is_hot']) ?: $map['is_hot'] = ['eq', $data['is_hot']];
        is_empty_parm($data['status']) ?: $map['status'] = ['eq', $data['status']];
        $map['is_delete'] = ['eq', 0];

        // 支持多个关键词搜索(空格分隔)
        if (!empty($data['keywords'])) {
            $keywords = explode(' ', $data['keywords']);
            foreach ($keywords as &$value) {
                $value = '%' . $value . '%';
            }

            unset($value);
            $map['name|short_name'] = ['like', $keywords, 'OR'];
        }

        // 回收站中不存在"上下架"概念
        if (!empty($data['is_delete'])) {
            $map['is_delete'] = ['eq', 1];
            unset($map['status']);
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
            $orderField = !empty($data['order_field']) ? $data['order_field'] : 'goods_id';

            $query
                ->where($map)
                ->order([$orderField => $orderType])
                ->page($pageNo, $pageSize);
        });

        if (false !== $result) {
            return ['items' => $result->toArray(), 'total_result' => $totalResult];
        }

        return false;
    }

    /**
     * 根据店铺分类获取指定类型的店铺(推荐,热卖,新品,积分,同品牌,同价位)
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsIndexType($data)
    {
        if (!$this->validateData($data, 'Goods.type_list')) {
            return false;
        }

        $map['status'] = ['eq', 1];
        $map['is_delete'] = ['eq', 0];
        $map['store_qty'] = ['gt', 0];
        empty($data['goods_category_id']) ?: $map['goods_category_id'] = ['eq', $data['goods_category_id']];
        is_empty_parm($data['brand_id']) ?: $map['brand_id'] = ['eq', $data['brand_id']];
        !isset($data['shop_price']) ?: $map['shop_price'] = ['between', $data['shop_price']];

        if (!is_empty_parm($data['goods_type'])) {
            switch ($data['goods_type']) {
                case 'integral':
                    $map['is_integral'] = ['gt', 0];
                    break;
                case 'recommend':
                    $map['is_recommend'] = ['eq', 1];
                    break;
                case 'new':
                    $map['is_new'] = ['eq', 1];
                    break;
                case 'hot':
                    $map['is_hot'] = ['eq', 1];
                    break;
            }
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

            $query
                ->field('goods_id,name,short_name,sales_sum,is_postage,market_price,shop_price,attachment')
                ->where($map)
                ->order(['sort' => 'asc', 'goods_id' => 'desc'])
                ->page($pageNo, $pageSize);
        });

        if (false !== $result) {
            return ['items' => $result->toArray(), 'total_result' => $totalResult];
        }

        return false;
    }

    /**
     * 筛选价格与品牌后获取店铺Id
     * @access private
     * @param  array $data 外部数据
     * @return array
     */
    private function getGoodsIdByBrandPrice($data)
    {
        if (empty($data['shop_price']) && empty($data['brand_id'])) {
            return [];
        }

        // 搜索条件
        $map = [];

        if (!empty($data['shop_price'])) {
            $map['shop_price'] = ['between', $data['shop_price']];
        }

        if (!empty($data['brand_id'])) {
            $map['brand_id'] = ['in', $data['brand_id']];
        }

        // 启用全局搜索条件
        return self::scope('global')->where($map)->column('goods_id');
    }

    /**
     * 筛选规格后获取店铺Id
     * @access private
     * @param  array $specList 规格列表
     * @return array
     */
    private function getGoodsIdBySpec($specList)
    {
        // 数组首位对应的是"cs_spec"中的"spec_id",非同一类值
        is_array(current($specList)) ?: array_shift($specList);

        if (empty($specList)) {
            return [];
        }

        // 子查询语句
        $subQuery = self::$specGoods->field("goods_id,concat('_', `key_name`, '_') as key_sub")->buildSql();

        foreach ($specList as $item) {
            if (is_array($item)) {
                array_shift($item);
                foreach ($item as &$value) {
                    $value = '%\_' . $value . '\_%';
                }

                unset($value);
            }

            if (empty($item)) {
                return [];
            }

            if (is_array($item)) {
                self::$specGoods->where(['s.key_sub' => ['like', $item, 'or']]);
            } else {
                self::$specGoods->whereOr(['s.key_sub' => ['like', '%\_' . $item . '\_%']]);
            }
        }

        return self::$specGoods->table($subQuery . ' s')->group('s.goods_id')->column('s.goods_id');
    }

    /**
     * 筛选属性后获取店铺Id
     * @access private
     * @param  array $attrList 属性列表
     * @return array
     */
    private function getGoodsIdByAttr($attrList)
    {
        if (empty($attrList)) {
            return [];
        }

        $attributeIdList = [];
        $valueList = [];

        if (is_array(current($attrList))) {
            foreach ($attrList as $value) {
                $attributeIdList[] = array_shift($value);
                $valueList = array_merge($valueList, $value);

                if (empty($value)) {
                    return [];
                }
            }
        } else {
            $attributeIdList[] = array_shift($attrList);
            $valueList = array_merge($valueList, $attrList);
        }

        if (empty($attributeIdList) || empty($valueList)) {
            return [];
        }

        $attributeIdList = array_unique($attributeIdList);
        $valueList = array_unique($valueList);

        // 排除主体属性
        $map['parent_id'] = ['neq', 0];
        $map['goods_attribute_id'] = ['in', $attributeIdList];
        $map['attr_value'] = ['in', $valueList];

        return self::$goodsAttr->where($map)->group('goods_id')->column('goods_id');
    }

    /**
     * 获取筛选条件选中后的菜单
     * @access private
     * @param  array $filterParam 筛选的参数
     * @return array
     */
    private function getFilterMenu($filterParam)
    {
        // 菜单列表
        $menuList = [];

        if (!empty($filterParam['brand'])) {
            $brandResult = Brand::cache(true, null, 'Brand')
                ->where(['brand_id' => ['in', $filterParam['brand']]])
                ->column('name', 'brand_id');

            if ($brandResult) {
                $brand['text'] = '品牌：';
                foreach ($filterParam['brand'] as $value) {
                    if (isset($brandResult[$value])) {
                        $brand['text'] .= $brandResult[$value] . '、';
                    }
                }
                !Str::endsWith($brand['text'], '、') ?: $brand['text'] = Str::substr($brand['text'], 0, -1);
                $brand['value'] = $filterParam['brand'];
                $brand['param'] = 'brand_id';
                $menuList[] = $brand;
            }
        }

        if (!empty($filterParam['price'])) {
            $price['text'] = '价格：' . implode($filterParam['price'], '-');
            $price['value'] = $filterParam['price'];
            $price['param'] = 'shop_price';
            $menuList[] = $price;
        }

        if (!empty($filterParam['spec'])) {
            $specList = [];
            $specItemList = [];
            $specGroup = [];

            if (!is_array(current($filterParam['spec']))) {
                $specList = array_shift($filterParam['spec']);
                $specItemList = $filterParam['spec'];
                $specGroup[$specList] = $specItemList;
            } else {
                foreach ($filterParam['spec'] as $item) {
                    $specKey = array_shift($item);
                    $specGroup[$specKey] = $item;
                    $specList[] = $specKey;
                    $specItemList = array_merge($specItemList, $item);
                }
            }

            $specResult = Spec::where(['spec_id' => ['in', $specList]])->column('name', 'spec_id');
            $specItemResult = SpecItem::where(['spec_item_id' => ['in', $specItemList]])->column('item_name', 'spec_item_id');

            foreach ($specGroup as $key => $item) {
                if (isset($specResult[$key])) {
                    $spec['text'] = $specResult[$key] . '：';
                    foreach ($item as $value) {
                        if (isset($specItemResult[$value])) {
                            $spec['text'] .= $specItemResult[$value] . '、';
                        }
                    }
                    !Str::endsWith($spec['text'], '、') ?: $spec['text'] = Str::substr($spec['text'], 0, -1);
                    $spec['value'] = array_merge([$key], $item);
                    $spec['param'] = 'spec_list';
                    $menuList[] = $spec;
                }
            }
        }

        if (!empty($filterParam['attr'])) {
            $attrList = [];
            $attrGroup = [];

            if (!is_array(current($filterParam['attr']))) {
                $attrList = array_shift($filterParam['attr']);
                $attrGroup[$attrList] = $filterParam['attr'];
            } else {
                foreach ($filterParam['attr'] as $item) {
                    $attrKey = array_shift($item);
                    $attrGroup[$attrKey] = $item;
                    $attrList[] = $attrKey;
                }
            }

            $attrResult = GoodsAttribute::where(['parent_id' => ['neq', 0]])
                ->where(['goods_attribute_id' => ['in', $attrList]])
                ->column('attr_name', 'goods_attribute_id');

            foreach ($attrGroup as $key => $item) {
                if (isset($attrResult[$key])) {
                    $attr['text'] = $attrResult[$key] . '：' . implode($attrGroup[$key], '、');
                    $attr['value'] = array_merge([$key], $item);
                    $attr['param'] = 'attr_list';
                    $menuList[] = $attr;
                }
            }
        }

        return $menuList;
    }

    /**
     * 根据店铺Id生成价格筛选菜单
     * @access private
     * @param  array $goodsIdList 店铺编号
     * @param  int   $page        价格分段
     * @return array
     */
    private function getFilterPrice($goodsIdList, $page = 5)
    {
        if (empty($goodsIdList)) {
            return [];
        }

        $priceResult = $this->where(['goods_id' => ['in', $goodsIdList]])->group('shop_price')->column('shop_price');
        if (!$priceResult) {
            return [];
        }

        rsort($priceResult);
        $maxPrice = (int)$priceResult[0]; // 最大金额值
        $pageSize = ceil($maxPrice / $page); // 每一段累积的值
        $price = [];

        for ($i = 0; $i < $page; $i++) {
            $start = $i * $pageSize;
            $end = $start + $pageSize;

            $isIn = false;
            foreach ($priceResult as $value) {
                if ($value > $start && $value <= $end) {
                    $isIn = true;
                    continue;
                }
            }

            if ($isIn == false)
                continue;

            if ($i == 0) {
                $price[] = ['text' => $end . '以下', 'value' => [$start, $end]];
            } elseif ($i == ($page - 1)) {
                $price[] = ['text' => $start . '以上', 'value' => [$start, $end]];
            } else {
                $price[] = ['text' => $start . '-' . $end, 'value' => [$start, $end]];
            }
        }

        return $price;
    }

    /**
     * 根据店铺Id生成品牌筛选菜单
     * @access private
     * @param  array $goodsIdList 店铺编号
     * @return array
     * @throws
     */
    private function getFilterBrand($goodsIdList)
    {
        if (empty($goodsIdList)) {
            return [];
        }

        // 子查询语句(此处查询没有进行全局查询)
        $map['brand_id'] = ['gt', 0];
        $map['goods_id'] = ['in', $goodsIdList];
        $subQuery = $this->field('brand_id')->where($map)->group('brand_id')->buildSql();

        $brandResult = Brand::cache(true, null, 'Brand')
            ->field('brand_id,name,phonetic,logo')
            ->where(['status' => ['eq', 1]])
            ->whereExp('brand_id', 'IN ' . $subQuery)
            ->order(['sort' => 'asc', 'brand_id' => 'desc'])
            ->select();

        $result = [];
        foreach ($brandResult as $key => $value) {
            $result[$key]['text'] = $value->getAttr('name');
            $result[$key]['value'] = $value->toArray();
        }

        return $result;
    }

    /**
     * 提取规格或属性的主项Id
     * @access private
     * @param  array  $filterParam 完整的筛选参数
     * @param  string $key         筛选参数的键名
     * @return array
     */
    private function getSpecOrAttrItem($filterParam, $key)
    {
        if (!isset($filterParam[$key])) {
            return [];
        }

        $data = [];
        foreach ($filterParam[$key] as $value) {
            if (is_array($value)) {
                $data[] = array_shift($value);
                continue;
            }

            $data[] = array_shift($filterParam[$key]);
            break;
        }

        return $data;
    }

    /**
     * 根据店铺Id生成规格筛选菜单
     * @access private
     * @param  array $goodsIdList 店铺编号
     * @param  array $filterParam 已筛选的条件
     * @return array
     */
    private function getFilterSpec($goodsIdList, $filterParam)
    {
        if (empty($goodsIdList)) {
            return [];
        }

        // 根据店铺编号获取所有规格项
        $specKeyList = self::$specGoods->field(['group_concat(key_name separator "_")' => 'key_name'])
            ->where(['goods_id' => ['in', $goodsIdList]])
            ->find();

        if ($specKeyList) {
            $specKeyList = array_unique(explode('_', $specKeyList->getAttr('key_name')));
            $specKeyList = array_filter($specKeyList);
        }

        if (empty($specKeyList)) {
            return [];
        }

        // 获取筛选已选中的规格
        $selectSpec = $this->getSpecOrAttrItem($filterParam, 'spec');

        // 获取可检索的规格
        $map = ['goods_type_id' => ['neq', 0], 'spec_index' => ['eq', 1]];
        empty($selectSpec) ?: $map['spec_id'] = ['not in', $selectSpec];

        $specResult = Spec::where($map)
            ->order(['sort' => 'asc', 'spec_id' => 'asc'])
            ->column('name', 'spec_id');

        // 根据规格获取对应的规格项
        $map = [
            'spec_item_id' => ['in', $specKeyList],
            'spec_id'      => ['in', array_keys($specResult)],
            'is_contact'   => ['eq', 1],
        ];

        $specItemResult = SpecItem::where($map)
            ->column('spec_id,item_name', 'spec_item_id');

        // 生成(排除不符合的)规格筛选菜单,必须以"$spec_result"做循环,否则排序无效
        $result = [];
        foreach ($specResult as $key => $item) {
            foreach ($specItemResult as $value) {
                if ($value['spec_id'] == $key) {
                    $result[$key]['text'] = $item;
                    $result[$key]['value'][] = $value;
                    unset($specItemResult[$value['spec_item_id']]); // 加速性能
                    continue;
                }
            }
        }

        // 必须"array_values"返回,否则排序无效
        return array_values($result);
    }

    /**
     * 根据店铺Id生成属性筛选菜单
     * @access private
     * @param  array $goodsIdList 店铺编号
     * @param  array $filterParam 已筛选的条件
     * @return array
     * @throws
     */
    private function getFilterAttr($goodsIdList, $filterParam)
    {
        if (empty($goodsIdList)) {
            return [];
        }

        // 根据店铺编号获取所有属性列表
        $goodsArrtResult = self::$goodsAttr->field('goods_attribute_id,attr_value,sort')
            ->where(['goods_id' => ['in', $goodsIdList]])
            ->where(['parent_id' => ['neq', 0], 'attr_value' => ['neq', '']])
            ->group('attr_value')
            ->order(['sort' => 'asc', 'goods_attribute_id' => 'asc'])
            ->select();

        if ($goodsArrtResult->isEmpty()) {
            return [];
        }

        // 获取筛选已选中的属性
        $selectAttr = $this->getSpecOrAttrItem($filterParam, 'attr');

        // 获取可检索的属性
        $map['parent_id&attr_index'] = ['neq', 0];
        $map['is_delete'] = ['eq', 0];
        empty($selectAttr) ?: $map['goods_attribute_id'] = ['not in', $selectAttr];
        $attrResult = GoodsAttribute::field('goods_attribute_id,attr_name')
            ->where($map)
            ->order(['sort' => 'asc', 'goods_attribute_id' => 'asc'])
            ->select();

        // 生成属性筛选菜单,必须以"$attr_result"做循环,否则排序无效
        $result = [];
        foreach ($attrResult as $item) {
            foreach ($goodsArrtResult as $key => $value) {
                if ($item['goods_attribute_id'] == $value['goods_attribute_id']) {
                    $result[$item['goods_attribute_id']]['text'] = $item['attr_name'];
                    $result[$item['goods_attribute_id']]['value'][] = $value->toArray();
                    unset($goodsArrtResult[$key]); // 加速性能
                    continue;
                }
            }
        }

        // 必须"array_values"返回,否则排序无效
        return array_values($result);
    }

    /**
     * 搜索店铺时返回对应的店铺分类
     * @access private
     * @param  array $goodsIdList 店铺编号
     * @param  array $data        外部数据
     * @return array
     */
    private function getFilterCate($goodsIdList, $data)
    {
        if (empty($data['keywords'])) {
            return [];
        }

        // 如果分类Id为空表示搜索全部店铺
        if (empty($data['goods_category_id'])) {
            $map['goods_id'] = ['in', $goodsIdList];
            $data['goods_category_id'] = array_unique($this->where($map)->column('goods_category_id'));

            $result = [];
            $cateList = GoodsCategory::getCategoryList();
            foreach ($data['goods_category_id'] as $item) {
                foreach ($cateList as $value) {
                    if ($value['goods_category_id'] == $item) {
                        $result[] = $value;
                        break;
                    }
                }
            }

            return $result;
        }

        $result = GoodsCategory::getCategoryList($data['goods_category_id'], false, true);
        if (false === $result) {
            return [];
        }

        return $result;
    }

    /**
     * 判断店铺分类是否存在,并且取该分类所有的子Id
     * @access public
     * @param  array &$data         外部数据
     * @param  array $goodsCateList 购物车店铺列表
     * @return bool
     */
    private function isCategoryList($data, &$goodsCateList)
    {
        $categoryId = isset($data['goods_category_id']) ? $data['goods_category_id'] : 0;
        $cateList = GoodsCategory::getCategoryList($categoryId, false, true);

        if (empty($cateList)) {
            return false;
        }

        $goodsCateList = array_column((array)$cateList, 'goods_category_id');
        return true;
    }

    /**
     * 根据店铺分类获取前台店铺列表页
     * @access public
     * @param  array $data 外部数据
     * @return array|false
     * @throws
     */
    public function getGoodsIndexList($data)
    {
        if (!$this->validateData($data, 'Goods.index_list')) {
            return false;
        }

        // 需要保留"$data['goods_category_id']",用以判断搜索时的分类条件
        $goodsCateList = [];
        if (!$this->isCategoryList($data, $goodsCateList)) {
            return $this->setError('店铺分类不存在');
        }

        // 搜索条件
        $map['goods_category_id'] = ['in', $goodsCateList];
        is_empty_parm($data['is_postage']) ?: $map['is_postage'] = ['eq', $data['is_postage']];
        is_empty_parm($data['is_integral']) ?: $map['is_integral'] = ['gt', 0];
        empty($data['bar_code']) ?: $map['bar_code'] = ['eq', $data['bar_code']];

        // 支持多个关键词搜索(空格分隔)
        if (!empty($data['keywords'])) {
            $keywords = explode(' ', $data['keywords']);
            foreach ($keywords as &$value) {
                $value = '%' . $value . '%';
            }

            unset($value);
            $map['name|short_name'] = ['like', $keywords, 'OR'];
        }

        $result = [];
        $filterParam = []; // 将筛选条件归类(所有的筛选都是数组)

        // 根据分类数组获取所有对应的店铺Id
        $goodsIdList = self::scope('global')->where($map)->column('goods_id');

        // 对店铺进行价格与品牌筛选
        if (!empty($data['shop_price']) || !empty($data['brand_id'])) {
            $priceBrandIdList = $this->getGoodsIdByBrandPrice($data);
            $goodsIdList = array_intersect($goodsIdList, $priceBrandIdList);

            empty($data['shop_price']) ?: $filterParam['price'] = $data['shop_price'];
            empty($data['brand_id']) ?: $filterParam['brand'] = $data['brand_id'];
        }

        // 对店铺进行规格筛选
        if (!empty($data['spec_list'])) {
            $specIdList = $this->getGoodsIdBySpec($data['spec_list']);
            $goodsIdList = array_intersect($goodsIdList, $specIdList);
            $filterParam['spec'] = $data['spec_list'];
        }

        // 对店铺进行属性筛选
        if (!empty($data['attr_list'])) {
            $attrIdList = $this->getGoodsIdByAttr($data['attr_list']);
            $goodsIdList = array_intersect($goodsIdList, $attrIdList);
            $filterParam['attr'] = $data['attr_list'];
        }

        // 根据筛选后的店铺Id生成各项菜单
        $result['filter_menu'] = $this->getFilterMenu($filterParam);
        $result['filter_price'] = empty($filterParam['price']) ? $this->getFilterPrice($goodsIdList) : [];
        $result['filter_brand'] = empty($filterParam['brand']) ? $this->getFilterBrand($goodsIdList) : [];
        $result['filter_spec'] = $this->getFilterSpec($goodsIdList, $filterParam);
        $result['filter_attr'] = $this->getFilterAttr($goodsIdList, $filterParam);
        $result['filter_cate'] = $this->getFilterCate($goodsIdList, $data);

        // 获取总数量,为空直接返回
        $totalResult = count($goodsIdList);
        if ($totalResult <= 0) {
            $result['total_result'] = 0;
            return $result;
        }

        $goodsResult = self::all(function ($query) use ($data, $goodsIdList) {
            // 翻页页数
            $pageNo = isset($data['page_no']) ? $data['page_no'] : 1;

            // 每页条数
            $pageSize = isset($data['page_size']) ? $data['page_size'] : config('paginate.list_rows');

            // 排序方式
            $orderType = !empty($data['order_type']) ? $data['order_type'] : 'desc';

            // 排序的字段
            $orderField = !empty($data['order_field']) ? $data['order_field'] : 'goods_id';

            // 过滤不需要的字段
            $field = 'goods_category_id,goods_code,goods_spu,goods_sku,bar_code,integral_type,give_integral,';
            $field .= 'is_integral,measure,unit,measure_type,keywords,description,content,goods_type_id,status,';
            $field .= 'is_delete,create_time,update_time';

            $query
                ->field($field, true)
                ->where(['goods_id' => ['in', $goodsIdList]])
                ->order([$orderField => $orderType])
                ->page($pageNo, $pageSize);
        });

        if (false !== $goodsResult) {
            $result['items'] = $goodsResult->toArray();
            $result['total_result'] = $totalResult;
            return $result;
        }

        return false;
    }

    /**
     * 设置店铺排序
     * @access public
     * @param  array $data 外部数据
     * @return bool
     */
    public function setGoodsSort($data)
    {
        if (!$this->validateData($data, 'Goods.sort')) {
            return false;
        }

        $map['goods_id'] = ['eq', $data['goods_id']];
        if (false !== $this->save(['sort' => $data['sort']], $map)) {
            return true;
        }

        return false;
    }

    /**
     * 获取店铺关键词联想词
     * @access public
     * @param  array $data 外部数据
     * @return array
     */
    public function getGoodsKeywordsSuggest($data)
    {
        if (!$this->validateData($data, 'Goods.suggest')) {
            return [];
        }

        $url = 'https://suggest.taobao.com/sug?code=utf-8&q=' . urlencode($data['keywords']);
        $httpResult = json_decode(Http::httpGet($url), true);

        $result = [];
        if (isset($httpResult['result'])) {
            $result = array_column($httpResult['result'], 0);
        }

        return $result;
    }

    /**
     * 复制一个店铺
     * @access public
     * @param  array $data 外部数据
     * @return false|array
     * @throws
     */
    public function copyGoodsItem($data)
    {
        if (!isset($data['goods_id'])) {
            return $this->setError('店铺编号不能为空');
        }

        $result = self::get(function ($query) use ($data) {
            $query->with('goodsSpecItem')->where(['goods_id' => ['eq', $data['goods_id']]]);
        });

        if (is_null($result)) {
            return $this->setError('店铺不存在');
        }

        // 清理原始数据
        $result->setAttr('goods_id', null);
        $result->setAttr('goods_code', '');
        $result->setAttr('comment_sum', 0);
        $result->setAttr('sales_sum', 0);
        $result->setAttr('create_time', time());
        $result->setAttr('update_time', time());
        $goodsData = $result->toArray();
        $goodsData['spec_combo'] = $goodsData['goods_spec_item'];
        unset($goodsData['goods_spec_item']);

        // 获取规格与属性配置数据
        $specConfigData = (new SpecConfig())->getSpecConfigItem($data);
        if ($specConfigData) {
            $goodsData['spec_config'] = $specConfigData['spec_config'];
        }

        $attrConfigData = (new GoodsAttrConfig())->getAttrConfigItem($data);
        if ($attrConfigData) {
            $goodsData['attr_config'] = $attrConfigData['attr_config'];
        }

        return $this->addGoodsItem($goodsData);
    }

    /**
     * 获取指定店铺店铺的规格菜单数据
     * @access public
     * @param  array $data 外部数据
     * @return array|bool
     */
    public function getGoodsSpecMenu($data)
    {
        if (!$this->validateData($data, 'Goods.item')) {
            return false;
        }

        $specList = $this->getGoodsSpecList($data);
        if (false === $specList) {
            return false;
        }

        if (empty($specList)) {
            return [];
        }

        $result = \app\common\service\SpecGoods::specItemToMenu($specList, $data['goods_id']);
        return ['spec_combo' => $specList, 'spec_config' => $result];
    }
}
