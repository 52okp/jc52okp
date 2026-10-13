/** Compatibility entry for the supported editor. */
require.config({paths:{'ckeditor5-core':'plugs/ckeditor5/core.umd','ckeditor5-zh':'plugs/ckeditor5/zh-cn.umd'}});
define(['plugs/ckeditor5/ckeditor-next'], function (CK) { return CK; });
