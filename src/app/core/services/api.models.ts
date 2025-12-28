export interface ListResponse<T> {
  data: T[];
  total: number;
}

export type Id = number;

export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'other';
export type AppointmentStatus = 'pending' | 'confirmed' | 'completed' | 'cancelled';
