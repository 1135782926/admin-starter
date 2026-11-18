<!-- 布局容器 -->
<template>
  <div class="app-layout">
    <aside id="app-sidebar">
      <ArtSidebarMenu />
    </aside>

    <main id="app-main">
      <div id="app-header">
        <ArtHeaderBar />
      </div>
      <div id="app-content">
        <ElEmpty v-if="route.name === 'NoAccessibleMenus'" description="暂无可访问菜单" />
        <ArtPageContent v-else />
      </div>
    </main>

    <div id="app-global">
      <ArtGlobalComponent />
    </div>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'AppLayout' })
  const route = useRoute()
</script>

<style lang="scss" scoped>
  @use './style';
</style>
