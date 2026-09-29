import { useState, useEffect, useCallback } from 'react';
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  TextInput,
  StyleSheet,
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  useWindowDimensions,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useLocalSearchParams, router } from 'expo-router';
import { WebView } from 'react-native-webview';
import { inboxApi } from '../../../src/api/inbox';
import { isPaymentRequiredError, getPaymentRequiredMessage } from '../../../src/api/client';
import { PaymentBanner } from '../../../src/components';
import type { Thread, Message } from '../../../src/api/types';

export default function ThreadScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { width: screenWidth } = useWindowDimensions();
  const [thread, setThread] = useState<Thread | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [replyText, setReplyText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [showReply, setShowReply] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);

  const loadThread = useCallback(async () => {
    if (!id) return;
    
    try {
      const response = await inboxApi.getThread(parseInt(id, 10));
      setThread(response.thread);
    } catch (error) {
      console.error('Failed to load thread:', error);
      Alert.alert('Error', 'Failed to load conversation');
      router.back();
    }
  }, [id]);

  useEffect(() => {
    setIsLoading(true);
    loadThread().finally(() => setIsLoading(false));
  }, [loadThread]);

  const handleArchive = async () => {
    if (!thread) return;
    
    try {
      await inboxApi.toggleArchive(thread.id);
      Alert.alert('Success', thread.is_archived ? 'Moved to inbox' : 'Archived');
      router.back();
    } catch (error) {
      if (isPaymentRequiredError(error)) {
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        Alert.alert('Error', 'Failed to update');
      }
    }
  };

  const handleTrash = async () => {
    if (!thread) return;
    
    Alert.alert(
      'Move to Trash',
      'Are you sure you want to move this conversation to trash?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Trash',
          style: 'destructive',
          onPress: async () => {
            try {
              await inboxApi.toggleTrash(thread.id);
              router.back();
            } catch (error) {
              if (isPaymentRequiredError(error)) {
                setPaymentError(getPaymentRequiredMessage(error));
              } else {
                Alert.alert('Error', 'Failed to move to trash');
              }
            }
          },
        },
      ]
    );
  };

  const handleSendReply = async () => {
    if (!thread || !replyText.trim()) return;
    
    setIsSending(true);
    try {
      const html = `<p>${replyText.replace(/\n/g, '</p><p>')}</p>`;
      const result = await inboxApi.reply(thread.id, { html });
      
      if (result.status === 'success') {
        setReplyText('');
        setShowReply(false);
        await loadThread();
        Alert.alert('Success', 'Reply sent');
      } else {
        Alert.alert('Error', result.message);
      }
    } catch (error) {
      if (isPaymentRequiredError(error)) {
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        Alert.alert('Error', 'Failed to send reply');
      }
    } finally {
      setIsSending(false);
    }
  };

  const renderMessage = (message: Message, index: number) => {
    const isOutbound = message.direction === 'outbound';
    const htmlContent = message.html || `<p>${message.text || ''}</p>`;
    
    const injectedHtml = `
      <!DOCTYPE html>
      <html>
        <head>
          <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
          <style>
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
              font-size: 15px;
              line-height: 1.5;
              color: #111827;
              margin: 0;
              padding: 12px;
              word-wrap: break-word;
              overflow-wrap: break-word;
            }
            img { max-width: 100%; height: auto; }
            a { color: #3B82F6; }
            blockquote {
              border-left: 3px solid #E5E7EB;
              margin: 8px 0;
              padding-left: 12px;
              color: #6B7280;
            }
          </style>
        </head>
        <body>${htmlContent}</body>
      </html>
    `;

    return (
      <View 
        key={message.id} 
        style={[styles.messageContainer, isOutbound && styles.messageOutbound]}
      >
        <View style={styles.messageHeader}>
          <View style={styles.messageFrom}>
            <Text style={styles.messageFromName}>
              {message.from_name || message.from}
            </Text>
            <Text style={styles.messageDirection}>
              {isOutbound ? '→ Sent' : '← Received'}
            </Text>
          </View>
          <Text style={styles.messageTime}>{message.sent}</Text>
        </View>
        
        {message.to && (
          <Text style={styles.messageTo}>To: {message.to}</Text>
        )}
        
        <View style={[styles.messageBody, { width: screenWidth - 56 }]}>
          <WebView
            originWhitelist={['*']}
            source={{ html: injectedHtml }}
            style={styles.webView}
            scrollEnabled={false}
            javaScriptEnabled={false}
            onShouldStartLoadWithRequest={() => false}
            injectedJavaScript={`
              document.body.style.height = document.body.scrollHeight + 'px';
              window.ReactNativeWebView.postMessage(document.body.scrollHeight);
            `}
          />
        </View>

        {message.attachments.length > 0 && (
          <View style={styles.attachments}>
            <Text style={styles.attachmentsLabel}>📎 {message.attachments.length} attachment(s)</Text>
          </View>
        )}
      </View>
    );
  };

  if (isLoading) {
    return (
      <SafeAreaView style={styles.loadingContainer}>
        <ActivityIndicator size="large" color="#3B82F6" />
      </SafeAreaView>
    );
  }

  if (!thread) {
    return (
      <SafeAreaView style={styles.errorContainer}>
        <Text style={styles.errorText}>Thread not found</Text>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <KeyboardAvoidingView 
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        {paymentError && (
          <PaymentBanner
            message={paymentError}
            onDismiss={() => setPaymentError(null)}
          />
        )}
        <View style={styles.subjectBar}>
          <Text style={styles.subject} numberOfLines={2}>
            {thread.subject || '(no subject)'}
          </Text>
          <View style={styles.actions}>
            <TouchableOpacity style={styles.actionButton} onPress={handleArchive}>
              <Text style={styles.actionIcon}>{thread.is_archived ? '📥' : '📦'}</Text>
            </TouchableOpacity>
            <TouchableOpacity style={styles.actionButton} onPress={handleTrash}>
              <Text style={styles.actionIcon}>🗑️</Text>
            </TouchableOpacity>
          </View>
        </View>

        <ScrollView style={styles.messages} contentContainerStyle={styles.messagesContent}>
          {thread.messages.map((message, index) => renderMessage(message, index))}
        </ScrollView>

        {showReply ? (
          <View style={styles.replyBox}>
            <TextInput
              style={styles.replyInput}
              placeholder="Write your reply..."
              placeholderTextColor="#9CA3AF"
              value={replyText}
              onChangeText={setReplyText}
              multiline
              numberOfLines={4}
              editable={!isSending}
            />
            <View style={styles.replyActions}>
              <TouchableOpacity
                style={styles.cancelButton}
                onPress={() => {
                  setShowReply(false);
                  setReplyText('');
                }}
                disabled={isSending}
              >
                <Text style={styles.cancelButtonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.sendButton, (!replyText.trim() || isSending) && styles.sendButtonDisabled]}
                onPress={handleSendReply}
                disabled={!replyText.trim() || isSending}
              >
                {isSending ? (
                  <ActivityIndicator color="#FFFFFF" size="small" />
                ) : (
                  <Text style={styles.sendButtonText}>Send Reply</Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        ) : (
          <TouchableOpacity style={styles.replyButton} onPress={() => setShowReply(true)}>
            <Text style={styles.replyButtonIcon}>↩️</Text>
            <Text style={styles.replyButtonText}>Reply</Text>
          </TouchableOpacity>
        )}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#F9FAFB',
  },
  flex: {
    flex: 1,
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#F9FAFB',
  },
  errorContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#F9FAFB',
  },
  errorText: {
    fontSize: 16,
    color: '#6B7280',
  },
  subjectBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  subject: {
    flex: 1,
    fontSize: 16,
    fontWeight: '600',
    color: '#111827',
    marginRight: 12,
  },
  actions: {
    flexDirection: 'row',
  },
  actionButton: {
    padding: 8,
    marginLeft: 4,
  },
  actionIcon: {
    fontSize: 20,
  },
  messages: {
    flex: 1,
  },
  messagesContent: {
    padding: 12,
  },
  messageContainer: {
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    padding: 14,
    marginBottom: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.03,
    shadowRadius: 4,
    elevation: 1,
  },
  messageOutbound: {
    backgroundColor: '#EFF6FF',
  },
  messageHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: 8,
  },
  messageFrom: {
    flex: 1,
  },
  messageFromName: {
    fontSize: 14,
    fontWeight: '600',
    color: '#111827',
  },
  messageDirection: {
    fontSize: 12,
    color: '#6B7280',
    marginTop: 2,
  },
  messageTime: {
    fontSize: 12,
    color: '#9CA3AF',
  },
  messageTo: {
    fontSize: 12,
    color: '#6B7280',
    marginBottom: 8,
  },
  messageBody: {
    minHeight: 60,
    overflow: 'hidden',
  },
  webView: {
    backgroundColor: 'transparent',
    minHeight: 80,
  },
  attachments: {
    marginTop: 12,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
  },
  attachmentsLabel: {
    fontSize: 13,
    color: '#6B7280',
  },
  replyButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#3B82F6',
    margin: 12,
    padding: 14,
    borderRadius: 12,
  },
  replyButtonIcon: {
    fontSize: 18,
    marginRight: 8,
  },
  replyButtonText: {
    fontSize: 16,
    fontWeight: '600',
    color: '#FFFFFF',
  },
  replyBox: {
    backgroundColor: '#FFFFFF',
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
    padding: 12,
  },
  replyInput: {
    backgroundColor: '#F9FAFB',
    borderWidth: 1,
    borderColor: '#E5E7EB',
    borderRadius: 8,
    padding: 12,
    fontSize: 15,
    color: '#111827',
    minHeight: 100,
    textAlignVertical: 'top',
  },
  replyActions: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    marginTop: 12,
  },
  cancelButton: {
    paddingHorizontal: 16,
    paddingVertical: 10,
    marginRight: 8,
  },
  cancelButtonText: {
    fontSize: 15,
    color: '#6B7280',
  },
  sendButton: {
    backgroundColor: '#3B82F6',
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 8,
  },
  sendButtonDisabled: {
    backgroundColor: '#93C5FD',
  },
  sendButtonText: {
    fontSize: 15,
    fontWeight: '600',
    color: '#FFFFFF',
  },
});
