// ============================================================
//  本地数据文件（Demo 阶段）
//  教程内容、工具网盘链接都在这里改。
//  以后后端恢复了，把 app.js 里的 useRemote 改 true，
//  这里的数据就改由接口返回，前端页面无需改动。
// ============================================================

// 教程列表
const tutorials = [
  {
    id: 'sd-install',
    title: 'Stable Diffusion 本地安装入门',
    cover: '',                       // 可填封面图地址，留空则显示默认色块
    tags: ['入门', '安装'],
    summary: '从零开始，在自己电脑上跑起来 SD WebUI。',
    updatedAt: '2026-06-01',
    // content 支持多段：type 为 text(正文) / heading(小标题) / image(图片) / tip(提示框)
    content: [
      { type: 'heading', text: '一、安装前准备' },
      { type: 'text', text: '建议显卡显存 6G 以上，N 卡体验最佳。准备好 Python 3.10.x 和 Git。' },
      { type: 'tip', text: '显存不够也别慌，后面有云端方案和优化参数的教程。' },
      { type: 'heading', text: '二、下载整合包' },
      { type: 'text', text: '新手推荐直接用整合包，免去配置环境的麻烦。整合包下载见「工具」页。' },
      { type: 'image', url: '' },
      { type: 'heading', text: '三、第一次出图' },
      { type: 'text', text: '填入提示词，点击生成。恭喜，你已经迈出第一步！' }
    ]
  },
  {
    id: 'prompt-basic',
    title: '提示词（Prompt）基础写法',
    cover: '',
    tags: ['提示词', '入门'],
    summary: '正向词、反向词、权重，三件套讲清楚。',
    updatedAt: '2026-06-03',
    content: [
      { type: 'heading', text: '正向提示词' },
      { type: 'text', text: '描述你想要的画面：主体、风格、画质词。用英文逗号分隔。' },
      { type: 'heading', text: '权重调节' },
      { type: 'text', text: '用 (词:1.3) 提高权重，[词] 降低权重。' },
      { type: 'tip', text: '别一次堆太多词，先少后多，逐步调试。' }
    ]
  },
  {
    id: 'lora-use',
    title: 'LoRA 模型的下载与使用',
    cover: '',
    tags: ['进阶', '模型'],
    summary: '让 SD 学会画特定角色 / 画风。',
    updatedAt: '2026-06-05',
    content: [
      { type: 'heading', text: '什么是 LoRA' },
      { type: 'text', text: 'LoRA 是小型微调模型，体积小、效果强，用来固定画风或角色。' },
      { type: 'heading', text: '放在哪个文件夹' },
      { type: 'text', text: '放到 models/Lora 目录下，刷新后即可在界面调用。' }
    ]
  }
];

// 工具推荐（每个工具可挂多个网盘链接）
const tools = [
  {
    id: 'sd-webui-pack',
    name: 'SD WebUI 秋叶整合包',
    desc: '免环境配置，解压即用，新手首选。',
    tags: ['整合包', '必备'],
    links: [
      { type: '百度网盘', url: 'https://pan.baidu.com/s/xxxxxx', code: 'sd01' },
      { type: '夸克网盘', url: 'https://pan.quark.cn/s/xxxxxx', code: '' },
      { type: '阿里云盘', url: 'https://www.alipan.com/s/xxxxxx', code: '' }
    ]
  },
  {
    id: 'model-pack',
    name: '常用大模型合集',
    desc: '写实、二次元、通用底模打包。',
    tags: ['模型'],
    links: [
      { type: '百度网盘', url: 'https://pan.baidu.com/s/yyyyyy', code: 'mod8' },
      { type: '夸克网盘', url: 'https://pan.quark.cn/s/yyyyyy', code: '' }
    ]
  },
  {
    id: 'lora-pack',
    name: '热门 LoRA 打包',
    desc: '精选画风与角色 LoRA。',
    tags: ['LoRA'],
    links: [
      { type: '夸克网盘', url: 'https://pan.quark.cn/s/zzzzzz', code: '' }
    ]
  }
];

module.exports = {
  tutorials,
  tools
};
