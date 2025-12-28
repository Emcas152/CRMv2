export type ApiFieldErrors = Record<string, string | string[]>;

export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
  errors?: ApiFieldErrors;
}

export function isApiEnvelope<T>(value: unknown): value is ApiEnvelope<T> {
  return !!value && typeof value === 'object' && 'success' in value && 'data' in value;
}

export function unwrapApiEnvelope<T>(value: ApiEnvelope<T> | T): T {
  return isApiEnvelope<T>(value) ? value.data : value;
}
