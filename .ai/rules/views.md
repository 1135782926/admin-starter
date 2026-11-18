---
paths:
  - 'web/src/views/**'
---

# Views

## 后台页面优先复用现有组件
新增或改造 Web 后台页面时，先检查现有共享组件及同类页面。列表筛选优先使用 `ArtSearchBar`，数据表格及其分页优先使用 `ArtTable`。通过现有 props、列配置和插槽实现业务差异。仅在现有组件无法满足明确需求时，针对缺口补充实现，并说明原因，避免重复搭建筛选表单、表格和分页。

## 后台表单优先使用 ArtForm
新增或改造后台数据录入表单时，优先复用 `ArtForm`，通过 `items`、`rules`、`props` 和命名插槽实现字段、校验及自定义内容。仅在 `ArtForm` 无法满足明确需求时手写 `ElForm`，并说明原因。

## 专用场景优先复用共享组件
- 表格工具栏优先使用 `ArtTableHeader`；表格操作使用 `ArtButtonTable`、`ArtButtonMore`；通用图标按钮使用 `ArtIconButton`。
- 单图上传优先使用 `ArtImageUpload`，富文本编辑使用 `ArtWangEditor`，Excel 导入导出使用 `ArtExcelImport`、`ArtExcelExport`。
- 操作结果页使用 `ArtResultPage`，403、404、500 等异常页使用 `ArtException`。
- 图片裁剪、视频播放、右键菜单和水印需求分别优先检查 `ArtCutterImg`、`ArtVideoPlayer`、`ArtMenuRight`、`ArtWatermark`。
- 仪表盘统计卡片与图表优先从 `web/src/components/core/cards/`、`web/src/components/core/charts/` 选择现有实现。
通过现有 props、事件和插槽实现业务差异，避免重复搭建或引入同类依赖。
