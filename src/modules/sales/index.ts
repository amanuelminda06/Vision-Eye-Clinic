export {
  SALES_ROLES,
  SALES_OWNER_ROLES,
  canViewRevenue,
  canApproveVoid,
  canVoidDirectly,
  canRequestVoid,
  canSeeAllSales,
} from './roles';

export {
  PAYMENT_METHODS,
  PAYMENT_LABEL_KEYS,
  SALE_STATUSES,
  STATUS_LABEL_KEYS,
  isPaymentMethod,
  isSaleStatus,
  SALES_PAGE_SIZE,
  SALES_EXPORT_CAP,
  MAX_SALE_LINES,
  MAX_QTY,
  MAX_REASON,
  MAX_PATIENT_QUERY,
} from './constants';
export type { PaymentMethodValue, SaleStatusValue } from './constants';

export {
  buildSaleWhere,
  scopeToActor,
  listSales,
  listSalesForExport,
  getSale,
  listVoidRequests,
  countPatientSales,
  listPatientSales,
  listDoctorOptions,
  listSaleAudit,
  listRevenueSales,
} from './queries';
export type {
  SaleListFilters,
  SaleListRow,
  SaleDetail,
  VoidRequestRow,
  PatientSaleRow,
} from './queries';

export {
  buildRevenueReport,
  resolvePeriod,
  fillDailyTrend,
  inRange,
  isPeriodPreset,
  percentChange,
  PERIOD_PRESETS,
} from './revenue';
export type {
  RevenueReport,
  RevenueBucket,
  RevenueSaleInput,
  DateRange,
  PeriodPreset,
} from './revenue';

export { default as NewSaleForm } from './components/NewSaleForm';
export { default as ReasonActionButton } from './components/ReasonActionButton';
export { default as ReceiptView } from './components/ReceiptView';
export { default as PrintButtons } from './components/PrintButtons';
export { default as PatientSalesList } from './components/PatientSalesList';
export { salePrefillQuery, resolveSalePrefill } from './prefill';
export type { SalePrefillParams, ResolvedSalePrefill } from './prefill';
