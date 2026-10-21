const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');

// Execute the production calculation functions against a minimal document.
const expert = read('includes/project-estimation.php');
const start = expert.indexOf('function updateProjectEstimation()');
const end = expert.indexOf("document.addEventListener('input'", start);
const elements = new Map();
const element = id => {
    if (!elements.has(id)) elements.set(id, { textContent: '', value: '0', style: {}, classList: { toggle() {} } });
    return elements.get(id);
};
const developers = ['Senior Dev', 'Junior Dev'].map(type => ({
    dataset: { department: 'Backend' },
    querySelector(selector) {
        return selector === '.developer-type-input' ? { value: type } : { value: '100' };
    },
}));
const context = {
    document: { querySelectorAll: () => developers, getElementById: element },
    salaries: { 'Senior Dev': 80000, 'Junior Dev': 25000 },
    dailyRatesCents: { 'Senior Dev': 307692, 'Junior Dev': 96154 },
    estimatedDays: { Backend: [20, 15] }, technologyCents: 10000,
    durationMonths: 10, techMonthly: 100, clientBudget: 200000,
    priorityMarkup: { rate: 0.30, percent: 30 },
    peso: { format: value => value.toFixed(2) },
};
element('additionalCostsInput').value = '99999';
vm.runInNewContext(expert.slice(start, end) + '\nupdateProjectEstimation();', context);
assert.equal(element('summaryDeveloperCost').textContent, '75961.50');
assert.equal(element('summaryTechCost').textContent, '100.00');
assert.equal(element('summaryOverallCost').textContent, '100401.18');

const report = read('modules/sales/report.php');
const reportStart = report.indexOf('function updateSummary()');
const reportEnd = report.indexOf('// Keep the preview', reportStart);
const inputs = { technology_cost: '100.00', developer_cost: '75961.50', resource_cost: '0.00' };
const billingContext = {
    document: {
        querySelector: selector => ({ value: inputs[selector.match(/name="([^"]+)"/)[1]] }),
        querySelectorAll: () => [], getElementById: element,
    },
    billingBaseline: { base: { technology_cost: 0, developer_cost: 0, resource_cost: 0 }, additions: {} },
};
vm.runInNewContext(report.slice(reportStart, reportEnd) + '\nupdateSummary();', billingContext);
assert.equal(element('summaryTotal').textContent, '100401.18');
billingContext.billingBaseline.base = { technology_cost: 10000, developer_cost: 7596150, resource_cost: 0 };
vm.runInNewContext('updateSummary();', billingContext);
assert.equal(element('summaryTotal').textContent, '0.00');
console.log('Pricing preview checks passed.');
