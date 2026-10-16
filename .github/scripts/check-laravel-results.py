"""Reject incomplete, skipped or failing mandatory Laravel CI coverage."""
import sys
import xml.etree.ElementTree as ET
from collections import Counter


REQUIRED = {
    'Tests\\Unit\\OrderStateMachineTest': 1,
    'Tests\\Unit\\PaymentStateMachineTest': 1,
    'Tests\\Feature\\CashPaymentTest': 1,
    'Tests\\Feature\\InternalDeliveryTest': 1,
    'Tests\\Feature\\OrderLifecycleTest': 1,
    'Tests\\Feature\\OrderAccessTest': 1,
    'Tests\\Feature\\PaymentPolicyTest': 1,
    'Tests\\Feature\\PaymentWebhookTest': 1,
    'Tests\\Feature\\RolesAndPermissionsTest': 1,
    'Tests\\Feature\\AdminIntegrationSecurityTest': 1,
    'Tests\\Feature\\AuditAccessBoundaryTest': 1,
    'Tests\\Feature\\ProductionConfigurationTest': 1,
    'Tests\\Feature\\InventoryConcurrencyTest': 1,
    'Tests\\Feature\\DriverAcceptanceConcurrencyTest': 2,
    'Tests\\Feature\\CouponCheckoutConcurrencyTest': 2,
    'Tests\\Feature\\AdminStaffConcurrencyTest': 1,
    'Tests\\Feature\\AdminRoleMysqlValidationTest': 1,
    'Tests\\Feature\\OrderPlacementMysqlTest': 1,
    'Tests\\Feature\\CiServicesIntegrationTest': 5,
}


def check(paths):
    if len(paths) != 2:
        raise ValueError('Expected the application and CI services JUnit reports')
    counts = Counter()
    for path in paths:
        root = ET.parse(path).getroot()
        cases = list(root.iter('testcase'))
        if not cases:
            raise ValueError(f'Empty JUnit report: {path}')
        for suite in root.iter('testsuite'):
            if any(int(suite.get(key, '0')) for key in ('errors', 'failures', 'skipped')):
                raise ValueError(f'Unsuccessful or skipped tests in {path}')
        for case in cases:
            if any(case.find(tag) is not None for tag in ('failure', 'error', 'skipped')):
                raise ValueError(f'Unsuccessful test: {case.get("name")}')
            classname = case.get('class') or case.get('classname', '').replace('.', '\\')
            counts[classname] += 1
    for classname, minimum in REQUIRED.items():
        if counts[classname] < minimum:
            raise ValueError(f'Missing mandatory coverage: {classname} ({counts[classname]}/{minimum})')
    return counts


if __name__ == '__main__':
    result = check(sys.argv[1:])
    print(f'Validated {sum(result.values())} executed tests and {len(REQUIRED)} mandatory classes; no skips/errors/failures')
