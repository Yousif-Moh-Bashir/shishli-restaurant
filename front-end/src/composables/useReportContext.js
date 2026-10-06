import { onMounted } from 'vue'
import { dashboardApi } from '../api/dashboard.api'
import { useApiState } from './useApiState'
export function useReportContext() {
  const state = useApiState()
  const load = () => state.run(signal => dashboardApi.bootstrap(signal))
  onMounted(load)
  return { ...state, load }
}
