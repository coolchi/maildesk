import { apiClient } from './client';
import type { InboxResponse, ThreadResponse, InboxFolder } from './types';

export interface InboxParams {
  folder?: InboxFolder;
  page?: number;
  per_page?: number;
}

export interface ReplyParams {
  html: string;
  cc?: string;
  bcc?: string;
}

export interface MarkReadParams {
  read: boolean;
}

export const inboxApi = {
  async getThreads(params: InboxParams = {}): Promise<InboxResponse> {
    const response = await apiClient.get<InboxResponse>('/inbox', { params });
    return response.data;
  },

  async getThread(threadId: number): Promise<ThreadResponse> {
    const response = await apiClient.get<ThreadResponse>(`/inbox/${threadId}`);
    return response.data;
  },

  async markRead(threadId: number, params: MarkReadParams): Promise<{ id: number; is_read: boolean; unread_count: number }> {
    const response = await apiClient.patch(`/inbox/${threadId}/read`, params);
    return response.data;
  },

  async toggleArchive(threadId: number): Promise<{ id: number; is_archived: boolean; unread_count: number }> {
    const response = await apiClient.post(`/inbox/${threadId}/archive`);
    return response.data;
  },

  async toggleSpam(threadId: number): Promise<{ id: number; is_spam: boolean; unread_count: number }> {
    const response = await apiClient.post(`/inbox/${threadId}/spam`);
    return response.data;
  },

  async toggleTrash(threadId: number): Promise<{ id: number; is_trashed: boolean; unread_count: number }> {
    const response = await apiClient.post(`/inbox/${threadId}/trash`);
    return response.data;
  },

  async reply(threadId: number, params: ReplyParams): Promise<{ status: string; message: string; message_id: string }> {
    const response = await apiClient.post(`/inbox/${threadId}/reply`, params);
    return response.data;
  },
};
