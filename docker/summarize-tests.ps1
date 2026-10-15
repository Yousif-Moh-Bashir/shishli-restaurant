$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$evidenceDirectory = Join-Path $PWD 'docs/docker/evidence'
$initial = Get-Content "$evidenceDirectory/initial-segment-passed.json" -Raw -Encoding utf8 | ConvertFrom-Json
$completedClasses = @{}
foreach ($entry in $initial.completed_classes) { $completedClasses[$entry.name] = $true }
$passedCases = New-Object 'System.Collections.Generic.HashSet[string]'
foreach ($caseName in $initial.passed_cases) {
    $caseClass = ($caseName -split '::',2)[0]
    if ($completedClasses.ContainsKey($caseClass)) { [void]$passedCases.Add($caseName) }
}
$initialPassed = $passedCases.Count
function Get-CaseKey($testCase) {
    $caseName = $testCase.GetAttribute('name') -replace ' with data set #(\d+)$','#$1' -replace ' with data set "(.+)"$','#$1'
    return $testCase.GetAttribute('class') + '::' + $caseName
}
$failuresBeforeFix = New-Object 'System.Collections.Generic.HashSet[string]'
$rawInitial = Get-Content "$evidenceDirectory/private/phpunit-debug.txt" -Raw -Encoding utf8
foreach ($match in [regex]::Matches($rawInitial,'(?m)^Test Failed \((.+)\)\r?$')) {
    $caseName = $match.Groups[1].Value
    if ($completedClasses.ContainsKey(($caseName -split '::',2)[0])) { [void]$failuresBeforeFix.Add($caseName) }
}
$suites = @()
foreach ($file in @('laravel-mysql-remaining.xml','fixes-final.xml','platform-fixes-final.xml','media-regression-final.xml')) {
    [xml]$document = Get-Content "$evidenceDirectory/$file" -Raw -Encoding utf8
    $suite = $document.testsuites.testsuite
    if ($file -ne 'laravel-mysql-remaining.xml' -and ([int]$suite.errors -ne 0 -or [int]$suite.failures -ne 0 -or [int]$suite.skipped -ne 0)) { throw "Unclean final suite: $file" }
    $suites += [pscustomobject]@{file=$file; tests=[int]$suite.tests; assertions=[int]$suite.assertions; errors=[int]$suite.errors; failures=[int]$suite.failures; skipped=[int]$suite.skipped}
    foreach ($testCase in $document.SelectNodes('//testcase')) {
        $key = Get-CaseKey $testCase
        if ($testCase.SelectSingleNode('failure') -or $testCase.SelectSingleNode('error')) {
            [void]$failuresBeforeFix.Add($key)
        } elseif (-not $testCase.SelectSingleNode('skipped')) {
            [void]$passedCases.Add($key)
        }
    }
}
$unresolved = @($failuresBeforeFix | Where-Object { -not $passedCases.Contains($_) })
if ($unresolved.Count) { throw ('Unresolved cases: '+($unresolved -join ', ')) }
$totalScope = [int]($initial.completed_classes | Measure-Object tests -Sum).Sum + $suites[0].tests
if ($passedCases.Count -ne $totalScope) { throw "Coverage mismatch: $($passedCases.Count) / $totalScope" }
$report = [pscustomobject]@{
    result='passed'; scope='All original Laravel cases covered across resumed batches; failed cases rerun after fixes, not one uninterrupted full run';
    engine='Oracle MySQL 8.4.11; persistent test volume and separate tmpfs test server';
    initial_completed_cases=[int]($initial.completed_classes | Measure-Object tests -Sum).Sum; initial_passed=$initialPassed;
    remaining_batch_cases=$suites[0].tests; unique_verified_cases=$passedCases.Count; failures_before_fixes=$failuresBeforeFix.Count; unresolved_failures=$unresolved.Count;
    suites=$suites; skipped=0; production_database_used=$false
}
[IO.File]::WriteAllText("$evidenceDirectory/test-summary.json",($report | ConvertTo-Json -Depth 6),(New-Object Text.UTF8Encoding $false))
$report | ConvertTo-Json -Depth 6
