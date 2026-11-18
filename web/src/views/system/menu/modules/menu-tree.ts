/** 菜单管理页共享的树形数据与父级约束工具。 */

export type MenuNode = Api.SystemManage.MenuRecord
export type MenuType = Api.SystemManage.MenuType
export type MenuOption = MenuNode & { disabled?: boolean }

export const menuTypeLabels: Record<MenuType, string> = {
  directory: '目录',
  menu: '菜单',
  button: '按钮'
}

export const menuOpenTypeLabels: Record<Api.SystemManage.MenuOpenType, string> = {
  page: '站内页面',
  external: '外部链接',
  iframe: '内嵌页面'
}

/** 查找指定节点。 */
export function findMenuNode(nodes: MenuNode[], id: number | null): MenuNode | undefined {
  if (id === null) return undefined
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findMenuNode(node.children ?? [], id)
    if (found) return found
  }
  return undefined
}

/** 节点自身及全部后代 ID，用于排除非法父级。 */
export function collectNodeAndDescendantIds(node: MenuNode): Set<number> {
  const ids = new Set<number>([node.id])
  for (const child of node.children ?? []) {
    for (const id of collectNodeAndDescendantIds(child)) ids.add(id)
  }
  return ids
}

/** 根据父节点类型返回允许创建的子节点类型。 */
export function allowedChildTypes(parent?: MenuNode | null): MenuType[] {
  if (!parent) return ['directory', 'menu']
  if (parent.type === 'directory') return ['directory', 'menu']
  if (parent.type === 'menu') return ['button']
  return []
}

/**
 * 过滤树节点：自身不匹配但后代匹配时保留为分组节点。
 */
export function filterMenuTree(
  nodes: MenuNode[],
  predicate: (node: MenuNode) => boolean
): MenuOption[] {
  const result: MenuOption[] = []
  for (const node of nodes) {
    const children = filterMenuTree(node.children ?? [], predicate)
    const matched = predicate(node)
    if (matched || children.length > 0) {
      result.push({ ...node, children, disabled: !matched })
    }
  }
  return result
}

/** 构造父级树选择器数据，排除编辑节点自身及后代。 */
export function buildParentOptions(
  nodes: MenuNode[],
  editing: MenuNode | null,
  type: MenuType
): MenuOption[] {
  const excluded = editing ? collectNodeAndDescendantIds(editing) : new Set<number>()
  const allowedParent = (node: MenuNode): boolean =>
    type === 'button' ? node.type === 'menu' : node.type === 'directory'

  return filterMenuTree(nodes, (node) => !excluded.has(node.id) && allowedParent(node))
}
