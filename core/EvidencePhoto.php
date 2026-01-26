<?php
/**
 * Evidence Photo Model Class
 * ERP v2 - Phase 5 v2
 * 
 * Handles photo evidence for route events:
 * - Upload and validate photos
 * - Enforce 4 photos per event
 * - Validate before status transitions
 */

class EvidencePhoto {
    private PDO $db;
    private AuditLog $audit;
    
    // Photo requirements
    const PHOTOS_REQUIRED = 4;
    const MAX_FILE_SIZE = 10485760; // 10MB
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const UPLOAD_DIR = 'uploads/evidence/';
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    /**
     * Upload a photo for a route event
     */
    public function upload(int $routeId, string $eventType, int $photoSeq, array $file): array {
        try {
            // Validate event type
            if (!in_array($eventType, ['Dispatch', 'Receive', 'Return', 'POSCheck'])) {
                return ['success' => false, 'error' => 'Event type ไม่ถูกต้อง'];
            }
            
            // Validate photo sequence
            if ($photoSeq < 1 || $photoSeq > self::PHOTOS_REQUIRED) {
                return ['success' => false, 'error' => 'Photo sequence ต้องเป็น 1-' . self::PHOTOS_REQUIRED];
            }
            
            // Validate route exists
            $stmt = $this->db->prepare("SELECT id, route_number FROM routes WHERE id = ?");
            $stmt->execute([$routeId]);
            $route = $stmt->fetch();
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            
            // Validate file
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                return ['success' => false, 'error' => 'ไม่พบไฟล์ที่อัพโหลด'];
            }
            
            if ($file['size'] > self::MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'ไฟล์มีขนาดใหญ่เกิน ' . (self::MAX_FILE_SIZE / 1048576) . 'MB'];
            }
            
            $mimeType = mime_content_type($file['tmp_name']);
            if (!in_array($mimeType, self::ALLOWED_TYPES)) {
                return ['success' => false, 'error' => 'รองรับเฉพาะไฟล์ JPEG, PNG, WebP'];
            }
            
            // Create upload directory
            $uploadPath = self::UPLOAD_DIR . $routeId . '/' . $eventType . '/';
            $fullPath = __DIR__ . '/../' . $uploadPath;
            
            if (!is_dir($fullPath)) {
                if (!mkdir($fullPath, 0755, true)) {
                    return ['success' => false, 'error' => 'ไม่สามารถสร้างโฟลเดอร์สำหรับเก็บรูปได้'];
                }
            }
            
            // Generate filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = sprintf('%s_%s_%d_%s.%s', 
                $route['route_number'],
                $eventType,
                $photoSeq,
                date('Ymd_His'),
                $ext
            );
            
            $filePath = $uploadPath . $filename;
            $fullFilePath = $fullPath . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $fullFilePath)) {
                return ['success' => false, 'error' => 'ไม่สามารถบันทึกไฟล์ได้'];
            }
            
            // Check if photo already exists for this slot
            $stmt = $this->db->prepare("
                SELECT id, file_path FROM evidence_photos 
                WHERE route_id = ? AND event_type = ? AND photo_seq = ?
            ");
            $stmt->execute([$routeId, $eventType, $photoSeq]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing record (replace photo)
                $stmt = $this->db->prepare("
                    UPDATE evidence_photos 
                    SET file_path = ?, file_size = ?, mime_type = ?, 
                        uploaded_by = ?, uploaded_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $filePath,
                    $file['size'],
                    $mimeType,
                    $_SESSION['user_id'],
                    $existing['id']
                ]);
                
                // Delete old file
                $oldFilePath = __DIR__ . '/../' . $existing['file_path'];
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
                
                $photoId = $existing['id'];
            } else {
                // Insert new record
                $stmt = $this->db->prepare("
                    INSERT INTO evidence_photos (
                        route_id, event_type, photo_seq, file_path, 
                        file_size, mime_type, uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $routeId,
                    $eventType,
                    $photoSeq,
                    $filePath,
                    $file['size'],
                    $mimeType,
                    $_SESSION['user_id']
                ]);
                
                $photoId = (int) $this->db->lastInsertId();
            }
            
            // Audit log
            $this->audit->log(
                'upload_photo',
                'EVIDENCE_PHOTO',
                $photoId,
                null,
                [
                    'route_id' => $routeId,
                    'event_type' => $eventType,
                    'photo_seq' => $photoSeq,
                    'file_path' => $filePath
                ]
            );
            
            return [
                'success' => true, 
                'id' => $photoId, 
                'file_path' => $filePath,
                'count' => $this->getPhotoCount($routeId, $eventType),
                'complete' => $this->isComplete($routeId, $eventType)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Delete a photo
     */
    public function delete(int $photoId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT ep.*, r.status as route_status
                FROM evidence_photos ep
                JOIN routes r ON ep.route_id = r.id
                WHERE ep.id = ?
            ");
            $stmt->execute([$photoId]);
            $photo = $stmt->fetch();
            
            if (!$photo) {
                return ['success' => false, 'error' => 'Photo not found'];
            }
            
            // Can only delete photos for routes in Draft/Confirmed status
            if (!in_array($photo['route_status'], ['Draft', 'Confirmed'])) {
                return ['success' => false, 'error' => 'ไม่สามารถลบรูปของ Route ที่ดำเนินการไปแล้ว'];
            }
            
            // Delete file
            $filePath = __DIR__ . '/../' . $photo['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete record
            $stmt = $this->db->prepare("DELETE FROM evidence_photos WHERE id = ?");
            $stmt->execute([$photoId]);
            
            // Audit log
            $this->audit->log(
                'delete_photo',
                'EVIDENCE_PHOTO',
                $photoId,
                ['file_path' => $photo['file_path']],
                null
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get photos for a route event
     */
    public function getPhotos(int $routeId, string $eventType): array {
        $stmt = $this->db->prepare("
            SELECT ep.*, u.full_name as uploaded_by_name
            FROM evidence_photos ep
            LEFT JOIN users u ON ep.uploaded_by = u.id
            WHERE ep.route_id = ? AND ep.event_type = ?
            ORDER BY ep.photo_seq
        ");
        $stmt->execute([$routeId, $eventType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all photos for a route (all events)
     */
    public function getAllPhotos(int $routeId): array {
        $stmt = $this->db->prepare("
            SELECT ep.*, u.full_name as uploaded_by_name
            FROM evidence_photos ep
            LEFT JOIN users u ON ep.uploaded_by = u.id
            WHERE ep.route_id = ?
            ORDER BY ep.event_type, ep.photo_seq
        ");
        $stmt->execute([$routeId]);
        
        $photos = $stmt->fetchAll();
        
        // Group by event type
        $grouped = [];
        foreach ($photos as $photo) {
            $grouped[$photo['event_type']][] = $photo;
        }
        
        return $grouped;
    }
    
    /**
     * Get photo count for an event
     */
    public function getPhotoCount(int $routeId, string $eventType): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM evidence_photos 
            WHERE route_id = ? AND event_type = ?
        ");
        $stmt->execute([$routeId, $eventType]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Check if photos are complete for an event
     */
    public function isComplete(int $routeId, string $eventType): bool {
        return $this->getPhotoCount($routeId, $eventType) >= self::PHOTOS_REQUIRED;
    }
    
    /**
     * Get photo completion status for all events
     */
    public function getCompletionStatus(int $routeId): array {
        $events = ['Dispatch', 'Receive', 'Return', 'POSCheck'];
        $status = [];
        
        foreach ($events as $event) {
            $count = $this->getPhotoCount($routeId, $event);
            $status[$event] = [
                'count' => $count,
                'required' => self::PHOTOS_REQUIRED,
                'complete' => $count >= self::PHOTOS_REQUIRED
            ];
        }
        
        return $status;
    }
    
    /**
     * Validate photos before status transition
     */
    public function validateBeforeTransition(int $routeId, string $eventType): array {
        if (!$this->isComplete($routeId, $eventType)) {
            $count = $this->getPhotoCount($routeId, $eventType);
            return [
                'valid' => false,
                'error' => sprintf(
                    'กรุณาอัพโหลดรูป %s ให้ครบ %d รูป (ปัจจุบันมี %d รูป)',
                    $eventType,
                    self::PHOTOS_REQUIRED,
                    $count
                )
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Get photo by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT ep.*, u.full_name as uploaded_by_name
            FROM evidence_photos ep
            LEFT JOIN users u ON ep.uploaded_by = u.id
            WHERE ep.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
