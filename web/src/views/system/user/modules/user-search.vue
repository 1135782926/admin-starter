<template>
  <ElCard class="mb-3" shadow="never">
    <ElForm :inline="true" @submit.prevent="emit('search')">
      <ElFormItem label="关键词" class="!mb-2">
        <ElInput v-model="form.keyword" clearable placeholder="用户名、昵称、邮箱或手机号" />
      </ElFormItem>
      <ElFormItem label="状态" class="!mb-2">
        <ElSelect v-model="form.status" clearable placeholder="全部" class="!w-32">
          <ElOption label="启用" :value="true" />
          <ElOption label="禁用" :value="false" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem class="!mb-2">
        <ElButton type="primary" native-type="submit">搜索</ElButton>
        <ElButton @click="emit('reset')">重置</ElButton>
      </ElFormItem>
    </ElForm>
  </ElCard>
</template>

<script setup lang="ts">
  const form = defineModel<Api.SystemManage.AdminSearchParams>({ required: true })
  const emit = defineEmits<{ search: []; reset: [] }>()
</script>
