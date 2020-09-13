<?php
/**
 * @copyright   Copyright (c) http://careyshop.cn All rights reserved.
 *
 * CareyShop    商品属性列表模型
 *
 * @author      zxm <252404501@qq.com>
 * @date        2017/4/20
 */

namespace app\common\model;

class ShopsAttr extends CareyShop
{
    /**
     * 隐藏属性
     * @var array
     */
    protected $hidden = [
        'shop_id',
    ];

    /**
     * 只读属性
     * @var array
     */
    protected $readonly = [
        'shop_id',
        'admin_id',
    ];

    /**
     * 字段类型或者格式转换
     * @var array
     */
    protected $type = [
        'shop_id'       => 'integer',
        'admin_id'      => 'integer',
        'sort'          => 'integer',
    ];

    /**
     * 添加店铺属性列表
     * @access public
     * @param  int   $shopId 店铺编号
     * @param  array $data    外部数据
     * @return bool
     * @throws
     */
    public function addShopsAttr($shopId, $data)
    {
        // 处理部分数据,并进行验证
        foreach ($data as $key => $value) {
            $data[$key]['shop_id'] = $shopId;

            if (!$this->validateData($data[$key], 'ShopsAttr')) {
                return false;
            }
        }

        $result = $this->allowField(true)->isUpdate(false)->saveAll($data);
        return false !== $result;
    }
}
