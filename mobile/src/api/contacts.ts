import { apiClient } from './client';
import type { ContactsResponse, Contact } from './types';

export interface ContactsParams {
  search?: string;
  page?: number;
  per_page?: number;
}

export interface CreateContactParams {
  email: string;
  first_name?: string;
  last_name?: string;
  company?: string;
}

export const contactsApi = {
  async getContacts(params: ContactsParams = {}): Promise<ContactsResponse> {
    const response = await apiClient.get<ContactsResponse>('/contacts', { params });
    return response.data;
  },

  async createContact(params: CreateContactParams): Promise<{ contact: Contact }> {
    const response = await apiClient.post('/contacts', params);
    return response.data;
  },
};
