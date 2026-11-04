import { defineStore } from 'pinia'
import { financeApi } from './api'
import { setFinanceTimezone } from './utils'

const key = 'shishli_finance_token'
const read = () => { try { return sessionStorage.getItem(key) || '' } catch { return '' } }
export const useFinanceSession = defineStore('finance-session', {
  state: () => ({ token: read(), user: null, permissions: [], branches: [], sources: [], categories: [], ready: false }),
  actions: {
    can(permission) { return this.permissions.includes(`finance.${permission}`) },
    clear() {
      this.token = ''; this.user = null; this.permissions = []; this.branches = []; this.sources = []; this.categories = []; this.ready = false
      try { sessionStorage.removeItem(key) } catch { /* Session remains in memory. */ }
    },
    async load() { const data = await financeApi.me(); setFinanceTimezone(data.timezone); Object.assign(this, data, { ready: true }) },
    async login(data) {
      const result = await financeApi.login(data)
      this.token = result.token
      try { await this.load(); sessionStorage.setItem(key, this.token) }
      catch (error) { try { await financeApi.logout() } catch { /* Login did not grant financial access. */ } this.clear(); throw error }
    },
    async logout() { try { await financeApi.logout() } finally { this.clear() } },
  },
})
