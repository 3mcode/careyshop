<?php
/**
 * @copyright   Copyright (c) http://careyshop.cn All rights reserved.
 *
 * CareyShop    商品控制器
 *
 * @author      zxm <252404501@qq.com>
 * @date        2017/4/13
 */

namespace app\api\controller\v1;

use app\api\controller\CareyShop;

class Shops extends CareyShop
{
    /**
     * 方法路由器
     * @access protected
     * @return array
     */
    protected static function initMethod()
    {
        return [
            // 获取指定编号店铺的基础数据
            'get.shops.select'           => ['getShopsSelect'],
        ];
    }
}
