export type AutoFlowColorMode = 'light' | 'dark';

export function useAutoFlowTheme() {
  const colorModeCookie = useCookie<AutoFlowColorMode>('autoflow-color-mode', {
    default: () => 'light',
    path: '/',
    sameSite: 'lax',
  });
  const colorMode = useState<AutoFlowColorMode>(
    'autoflow-color-mode',
    () => colorModeCookie.value,
  );

  watch(colorMode, (mode) => {
    colorModeCookie.value = mode;
  }, { flush: 'sync' });

  const isDark = computed(() => colorMode.value === 'dark');

  function toggle() {
    colorMode.value = isDark.value ? 'light' : 'dark';
  }

  return { colorMode, isDark, toggle };
}
