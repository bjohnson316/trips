// Bundles just the Firebase pieces the app uses into one file (window.FB).
// Rebuild with: npm install && npm run build
import { initializeApp } from 'firebase/app';
import {
  initializeFirestore, persistentLocalCache, persistentMultipleTabManager,
  collection, doc, getDoc, getDocs, setDoc, updateDoc, deleteDoc, deleteField,
  onSnapshot, writeBatch
} from 'firebase/firestore';

export {
  initializeApp, initializeFirestore, persistentLocalCache, persistentMultipleTabManager,
  collection, doc, getDoc, getDocs, setDoc, updateDoc, deleteDoc, deleteField,
  onSnapshot, writeBatch
};
