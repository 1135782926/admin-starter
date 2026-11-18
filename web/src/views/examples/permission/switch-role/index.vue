<template>
  <ElCard class="art-card-xs">
    <template #header>当前管理员</template>
    <p>用户名：{{ userStore.info.userName }}</p>
    <p>角色：{{ userStore.info.roles?.join('、') || '无角色' }}</p>
    <ElButton type="primary" :loading="switching" @click="switchAccount">退出并切换账号</ElButton>
  </ElCard>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'PermissionSwitchRole' })
  const userStore = useUserStore()
  const switching = ref(false)

  const switchAccount = async () => {
    if (switching.value) return
    switching.value = true
    try {
      await userStore.logout()
    } finally {
      switching.value = false
    }
  }
</script>
