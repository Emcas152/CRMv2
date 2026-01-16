import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { firstValueFrom } from 'rxjs';

import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
  CardHeaderComponent,
  ColComponent,
  FormControlDirective,
  FormDirective,
  FormLabelDirective,
  RowComponent,
  TableDirective
} from '@coreui/angular';

import {
  ConversationDetail,
  ConversationListItem,
  ConversationsService,
  MessageItem
} from '../../../core/services/conversations.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-conversations-page',
  templateUrl: './conversations-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    TableDirective,
    ButtonDirective,
    AlertComponent,
    FormDirective,
    FormLabelDirective,
    FormControlDirective
  ]
})
export class ConversationsPageComponent implements OnInit {
  readonly #conversations = inject(ConversationsService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  isLoadingDetail = false;
  error: string | null = null;
  submitError: string | null = null;
  messageError: string | null = null;
  total = 0;
  conversations: ConversationListItem[] = [];

  selectedId: Id | null = null;
  selectedConversation: ConversationDetail | null = null;
  messages: MessageItem[] = [];

  readonly form = this.#fb.nonNullable.group({
    subject: [''],
    participant_user_ids: ['', [Validators.required]],
    first_message: ['', [Validators.required]]
  });

  readonly messageForm = this.#fb.nonNullable.group({
    body: ['', [Validators.required]]
  });

  ngOnInit(): void {
    void this.refresh();
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      const res = await firstValueFrom(this.#conversations.list());
      this.total = res.total;
      this.conversations = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async openConversation(c: ConversationListItem): Promise<void> {
    this.selectedId = c.id;
    this.selectedConversation = null;
    this.messages = [];
    this.messageError = null;
    this.isLoadingDetail = true;

    try {
      const [detail, msgs] = await Promise.all([
        firstValueFrom(this.#conversations.get(c.id)),
        firstValueFrom(this.#conversations.listMessages(c.id))
      ]);
      this.selectedConversation = detail;
      this.messages = msgs.data;
      await firstValueFrom(this.#conversations.markRead(c.id));
    } catch (err: any) {
      this.messageError = this.#formatError(err);
    } finally {
      this.isLoadingDetail = false;
    }
  }

  async createConversation(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid) return;

    const raw = this.form.getRawValue();
    const participantIds = raw.participant_user_ids
      .split(',')
      .map(v => Number(v.trim()))
      .filter(v => Number.isFinite(v) && v > 0);

    if (participantIds.length < 1) {
      this.submitError = 'Debes incluir al menos un participante.';
      return;
    }

    try {
      const created = await firstValueFrom(this.#conversations.create({
        subject: raw.subject.trim() || undefined,
        participant_user_ids: participantIds as Id[],
        first_message: raw.first_message.trim()
      }));
      this.form.reset({ subject: '', participant_user_ids: '', first_message: '' });
      await this.refresh();
      await this.openConversation({ id: created.id, created_by: created.created_by } as any);
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    }
  }

  async sendMessage(): Promise<void> {
    this.messageError = null;
    this.messageForm.markAllAsTouched();
    if (this.messageForm.invalid || this.selectedId === null) return;

    const raw = this.messageForm.getRawValue();
    try {
      await firstValueFrom(this.#conversations.sendMessage(this.selectedId, raw.body.trim()));
      this.messageForm.reset({ body: '' });
      await this.openConversation({ id: this.selectedId, created_by: 0 } as any);
    } catch (err: any) {
      this.messageError = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las conversaciones.';
  }
}
