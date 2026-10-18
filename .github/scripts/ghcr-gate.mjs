import { appendFileSync, readFileSync } from 'node:fs'

// Fail closed: never substitute github.sha (default branch HEAD) for the CI SHA.
const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'))
const repo = process.env.GITHUB_REPOSITORY
const api = async path => {
  const response = await fetch(`https://api.github.com/repos/${repo}/${path}`, {
    headers: { Authorization: `Bearer ${process.env.GH_TOKEN}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28' },
  })
  if (!response.ok) throw new Error(`GitHub API ${response.status}: ${path}`)
  return response.json()
}
const deny = message => { throw new Error(message) }
const trigger = event.workflow_run
if (!trigger || process.env.GITHUB_EVENT_NAME !== 'workflow_run') deny('Publishing requires workflow_run')
const run = await api(`actions/runs/${trigger.id}`)
const workflow = await api('actions/workflows/ci.yml')
const head = await api('git/ref/heads/master')
if (run.workflow_id !== workflow.id || run.path !== '.github/workflows/ci.yml'
  || run.event !== 'push' || run.head_branch !== 'master'
  || run.head_repository?.full_name !== repo || run.repository?.full_name !== repo
  || run.status !== 'completed' || run.conclusion !== 'success'
  || run.head_sha !== trigger.head_sha || run.run_attempt !== trigger.run_attempt
  || head.object.sha !== run.head_sha) deny('CI identity, SHA, attempt, success or current master check failed')
const latest = await api(`actions/workflows/ci.yml/runs?branch=master&event=push&head_sha=${run.head_sha}&per_page=1`)
if (latest.workflow_runs?.[0]?.id !== run.id) deny('A newer CI run exists for this SHA')
const jobs = await api(`actions/runs/${run.id}/attempts/${run.run_attempt}/jobs?per_page=100`)
for (const name of ['Laravel / PHP 8.3 / MySQL 8.4', 'Vue / Node 22', 'Docker Compose / production builds']) {
  if (!jobs.jobs.some(job => job.name === name && job.conclusion === 'success')) deny(`Required CI job missing or unsuccessful: ${name}`)
}
if (!/^[a-f0-9]{40}$/.test(run.head_sha)) deny('Invalid SHA')
appendFileSync(process.env.GITHUB_OUTPUT, `sha=${run.head_sha}\n`)
console.log(`Authorized CI run ${run.id}, attempt ${run.run_attempt}, SHA ${run.head_sha}`)
