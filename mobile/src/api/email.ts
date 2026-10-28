import { apiClient } from './client';

export interface SendEmailParams {
  from: string;
  to: string;
  subject: string;
  html?: string;
  text?: string;
  cc?: string;
  bcc?: string;
  reply_to?: string;
}

export const emailApi = {
  async send(params: SendEmailParams): Promise<{ status: string; message: string; email_id: string }> {
    const response = await apiClient.post('/emails', params);
    return response.data;
  },
};
