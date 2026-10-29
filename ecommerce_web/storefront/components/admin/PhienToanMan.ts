import { createContext } from 'react';
import type { AdminSession } from '@/lib/adminAuth';

/** Phiên đăng nhập cho các màn toàn màn hình (app/admin/(toan-man)). */
export const PhienToanMan = createContext<AdminSession | null>(null);
