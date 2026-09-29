import { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  Alert,
  ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { emailApi } from '../../src/api/email';
import { useAuth } from '../../src/contexts/AuthContext';

export default function ComposeScreen() {
  const { currentWorkspace } = useAuth();
  const [to, setTo] = useState('');
  const [cc, setCc] = useState('');
  const [bcc, setBcc] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [from, setFrom] = useState(currentWorkspace?.email || '');
  const [isSending, setIsSending] = useState(false);
  const [showCcBcc, setShowCcBcc] = useState(false);

  const handleSend = async () => {
    if (!to.trim()) {
      Alert.alert('Error', 'Please enter a recipient');
      return;
    }
    if (!subject.trim()) {
      Alert.alert('Error', 'Please enter a subject');
      return;
    }
    if (!body.trim()) {
      Alert.alert('Error', 'Please enter a message');
      return;
    }
    if (!from.trim()) {
      Alert.alert('Error', 'Please enter a from address');
      return;
    }

    setIsSending(true);
    try {
      const html = `<p>${body.replace(/\n/g, '</p><p>')}</p>`;
      const result = await emailApi.send({
        from,
        to,
        subject,
        html,
        text: body,
        cc: cc.trim() || undefined,
        bcc: bcc.trim() || undefined,
      });

      if (result.status === 'success') {
        Alert.alert('Success', 'Email sent!');
        setTo('');
        setCc('');
        setBcc('');
        setSubject('');
        setBody('');
      } else {
        Alert.alert('Error', result.message);
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } };
      Alert.alert('Error', err.response?.data?.message || 'Failed to send email');
    } finally {
      setIsSending(false);
    }
  };

  const handleClear = () => {
    Alert.alert(
      'Clear Draft',
      'Are you sure you want to clear this email?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Clear',
          style: 'destructive',
          onPress: () => {
            setTo('');
            setCc('');
            setBcc('');
            setSubject('');
            setBody('');
          },
        },
      ]
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <View style={styles.header}>
          <Text style={styles.title}>Compose</Text>
          <View style={styles.headerActions}>
            <TouchableOpacity style={styles.headerButton} onPress={handleClear}>
              <Text style={styles.headerButtonText}>Clear</Text>
            </TouchableOpacity>
          </View>
        </View>

        <ScrollView style={styles.form} keyboardShouldPersistTaps="handled">
          <View style={styles.field}>
            <Text style={styles.label}>From</Text>
            <TextInput
              style={styles.input}
              placeholder="your@email.com"
              placeholderTextColor="#9CA3AF"
              value={from}
              onChangeText={setFrom}
              autoCapitalize="none"
              keyboardType="email-address"
              editable={!isSending}
            />
          </View>

          <View style={styles.field}>
            <Text style={styles.label}>To</Text>
            <TextInput
              style={styles.input}
              placeholder="recipient@example.com"
              placeholderTextColor="#9CA3AF"
              value={to}
              onChangeText={setTo}
              autoCapitalize="none"
              keyboardType="email-address"
              editable={!isSending}
            />
          </View>

          <TouchableOpacity
            style={styles.ccBccToggle}
            onPress={() => setShowCcBcc(!showCcBcc)}
          >
            <Text style={styles.ccBccToggleText}>
              {showCcBcc ? 'Hide CC/BCC' : 'Add CC/BCC'}
            </Text>
          </TouchableOpacity>

          {showCcBcc && (
            <>
              <View style={styles.field}>
                <Text style={styles.label}>CC</Text>
                <TextInput
                  style={styles.input}
                  placeholder="cc@example.com"
                  placeholderTextColor="#9CA3AF"
                  value={cc}
                  onChangeText={setCc}
                  autoCapitalize="none"
                  keyboardType="email-address"
                  editable={!isSending}
                />
              </View>

              <View style={styles.field}>
                <Text style={styles.label}>BCC</Text>
                <TextInput
                  style={styles.input}
                  placeholder="bcc@example.com"
                  placeholderTextColor="#9CA3AF"
                  value={bcc}
                  onChangeText={setBcc}
                  autoCapitalize="none"
                  keyboardType="email-address"
                  editable={!isSending}
                />
              </View>
            </>
          )}

          <View style={styles.field}>
            <Text style={styles.label}>Subject</Text>
            <TextInput
              style={styles.input}
              placeholder="Subject"
              placeholderTextColor="#9CA3AF"
              value={subject}
              onChangeText={setSubject}
              editable={!isSending}
            />
          </View>

          <View style={styles.field}>
            <Text style={styles.label}>Message</Text>
            <TextInput
              style={[styles.input, styles.bodyInput]}
              placeholder="Write your message..."
              placeholderTextColor="#9CA3AF"
              value={body}
              onChangeText={setBody}
              multiline
              numberOfLines={10}
              textAlignVertical="top"
              editable={!isSending}
            />
          </View>
        </ScrollView>

        <View style={styles.footer}>
          <TouchableOpacity
            style={[styles.sendButton, isSending && styles.sendButtonDisabled]}
            onPress={handleSend}
            disabled={isSending}
          >
            {isSending ? (
              <ActivityIndicator color="#FFFFFF" />
            ) : (
              <>
                <Text style={styles.sendButtonIcon}>📨</Text>
                <Text style={styles.sendButtonText}>Send Email</Text>
              </>
            )}
          </TouchableOpacity>
        </View>
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
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  title: {
    fontSize: 20,
    fontWeight: '700',
    color: '#111827',
  },
  headerActions: {
    flexDirection: 'row',
  },
  headerButton: {
    paddingHorizontal: 12,
    paddingVertical: 6,
  },
  headerButtonText: {
    fontSize: 15,
    color: '#6B7280',
  },
  form: {
    flex: 1,
    padding: 16,
  },
  field: {
    marginBottom: 16,
  },
  label: {
    fontSize: 14,
    fontWeight: '500',
    color: '#374151',
    marginBottom: 6,
  },
  input: {
    backgroundColor: '#FFFFFF',
    borderWidth: 1,
    borderColor: '#E5E7EB',
    borderRadius: 8,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    color: '#111827',
  },
  bodyInput: {
    minHeight: 160,
    textAlignVertical: 'top',
  },
  ccBccToggle: {
    marginBottom: 16,
  },
  ccBccToggleText: {
    fontSize: 14,
    color: '#3B82F6',
  },
  footer: {
    backgroundColor: '#FFFFFF',
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
    padding: 16,
  },
  sendButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#3B82F6',
    borderRadius: 12,
    paddingVertical: 14,
  },
  sendButtonDisabled: {
    backgroundColor: '#93C5FD',
  },
  sendButtonIcon: {
    fontSize: 18,
    marginRight: 8,
  },
  sendButtonText: {
    fontSize: 16,
    fontWeight: '600',
    color: '#FFFFFF',
  },
});
