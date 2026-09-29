export interface User {
  id: number;
  name: string;
  email: string;
  is_platform_admin: boolean;
}

export interface Workspace {
  id: number;
  name: string;
  plan: string;
  product: string;
  provider: string;
  providerName: string;
  providerOk: boolean;
  email: string;
  owner: string;
  subdomain: string | null;
  host: string | null;
  customDomain: string | null;
  status: string;
  color: string;
}

export interface LoginResponse {
  token: string;
  user: User;
  workspaces: Workspace[];
}

export interface MeResponse {
  user: User;
  workspaces: Workspace[];
}

export interface Message {
  id: string;
  from: string;
  from_name: string | null;
  to: string;
  html: string;
  text: string;
  sent: string;
  direction: 'inbound' | 'outbound';
  cc: string;
  bcc: string;
  status: string;
  error: string | null;
  can_retry: boolean;
  forwarded: boolean;
  attachments: Attachment[];
}

export interface Attachment {
  id: number;
  filename: string;
  mime_type: string;
  size: number;
  url: string;
}

export interface Thread {
  id: number;
  subject: string;
  snippet: string;
  ai: AiMetadata | null;
  from: string;
  from_email: string;
  from_name: string | null;
  to: string;
  unread: boolean;
  is_archived: boolean;
  is_spam: boolean;
  is_trashed: boolean;
  trashed_at: string | null;
  label: string;
  messages: Message[];
  message_count: number;
  updated: string;
}

export interface AiMetadata {
  priority: string | null;
  intent: string | null;
  language: string | null;
  summary?: string;
  action_items?: string[];
}

export interface Contact {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  company: string;
  status: string;
  properties: Record<string, unknown>;
  created: string;
  added: string;
}

export interface Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface InboxResponse {
  threads: Thread[];
  pagination: Pagination;
  folder: string;
  unread_count: number;
}

export interface ThreadResponse {
  thread: Thread;
}

export interface ContactsResponse {
  contacts: Contact[];
  pagination: Pagination;
  search: string | null;
}

export type InboxFolder = 'inbox' | 'archive' | 'spam' | 'trash';
