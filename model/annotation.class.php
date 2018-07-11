<?php
/**
 * Description of Annotation
 * Methods:
 * create_annotation
 * delete
 * read (abstract)
 *
 * @package   mod_pdfannotator
 * @copyright 2018 RWTH Aachen, Rabea de Groot and Anna Heynkes(see README.md)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class pdfannotator_annotation {

    public $id;
    public $pdfannotatorid; // instance id
    public $page;
    public $userid;
    public $annotationtypeid;
    public $data;
    public $timecreated;
    public $timemodified;

    public function __construct($id) {
        global $DB;
        $record = $DB->get_record('pdfannotator_annotations', ['id' => $id], '*', MUST_EXIST);

        $this->id = $id;
        $this->pdfannotatorid = $record->pdfannotatorid;
        $this->page = $record->page;
        $this->userid = $record->userid;
        $this->annotationtypeid = $record->annotationtypeid;
        $this->data = json_decode($record->data);
        $this->timecreated = $record->timecreated;
        $this->timemodified = $record->timemodified;
    }

    /**
     * Returns the name of the type of the annotation.
     * @global type $DB
     * @return type
     */
    public function get_annotationtype() {
        global $DB;
        $type = $DB->get_field('pdfannotator_annotationtypes', 'name', ['id' => $this->annotationtypeid]);

        return $type;
    }

    /**
     * Returns the number of page on which the annotation was created.
     * @return type Integer
     */
    public function get_page_of_annotation() {
        return $this->page;
    }

    /**
     * This method creates a new record in the database table named mdl_pdfannotator_annotations and returns its id
     *
     * @global type $DB
     * @global type $USER
     * @param type $documentid specifies the pdf file to which this annotation belongs
     * @param type $pageid specifies the page within that pdf file
     * @param type $type child class (highlight, strikeout, area, textbox, drawing, comment or point)
     * @param type $itemid identifies the record in the respective child class table, e.g. highlights
     * @return int (or boolean false)
     */
    public static function create_annotation($documentid, $pageid, $type, $itemid) {

        global $DB;
        global $USER;
        $datarecord = new stdClass();
        $datarecord->userid = $USER->id;
        $datarecord->documentid = $documentid;
        $datarecord->pageid = $pageid;
        $datarecord->type = $type;
        $datarecord->itemid = $itemid;
        $annotationid = $DB->insert_record('pdfannotator_annotations', $datarecord, $returnid = true);
        return $annotationid;
    }

    /**
     * Method updates data attribute (consisting of width, color and lines)
     * in mdl_pdfannotator_drawings after a drawing was shifted in position
     *
     * @global type $DB
     * @param type $annotationid
     * @param type $newdata
     * @return type int 1 for success
     */
    public static function update($annotationid, $newdata) {

        global $DB;
        $dataobject = array("id" => $annotationid, "data" => json_encode($newdata));
        return $DB->update_record('pdfannotator_annotations', $dataobject, $bulk = false);
    }

    /**
     * Method deletes the specified annotation and all comments attached to it,
     * if the user is allowed to do so
     *
     * @global type $DB
     * @param type $annotationId
     * @param type $cmid
     * @return boolean
     */
    public static function delete($annotationid, $cmid = null, $righttobeforgottenwasinvoked = null) {

        global $DB;
        $table1 = 'pdfannotator_annotations';
        $table2 = 'pdfannotator_comments';

        if (!$DB->record_exists($table1, array('id' => $annotationid))) {
            return false;
        }

        // Check user rights to delete this annotation and all its attached comments.
        $deletionallowed = self::deletion_allowed($annotationid, $cmid);

        // Delete annotation.
        if ($deletionallowed[0] === true || $righttobeforgottenwasinvoked === true) {

            $conditions = array('annotationid' => $annotationid, 'isquestion' => '1');
            $questionid = $DB->get_field('pdfannotator_comments', 'id', $conditions);

            // Delete all comments of this annotation.
            // But first insert reported comments into the archive.
            $comments = $DB->get_records('pdfannotator_comments', array("annotationid" => $annotationid));
            foreach ($comments as $commentdata) {
                $DB->delete_records('pdfannotator_votes', array("commentid" => $commentdata->id));
                // If the comment was not deleted, but reported, then insert the record into the archive.
                if ($commentdata->isdeleted == 0 && $DB->record_exists('pdfannotator_reports', ['commentid' => $commentdata->id])) {
                    unset($commentdata->id);
                    $DB->insert_record('pdfannotator_comments_archiv', $commentdata);
                }
            }
            $success = $DB->delete_records($table2, array("annotationid" => $annotationid));

            // Delete subscriptions to the question.
            $DB->delete_records('pdfannotator_subscriptions', array('annotationid' => $annotationid));

            // Delete the annotation itself.
            $success = $DB->delete_records($table1, array("id" => $annotationid));

            if ($righttobeforgottenwasinvoked) {
                return;
            }

            if ($success == null || $success != 1) {
                return false;
            }

            return true;
        } else {
            return $deletionallowed[1];
        }
    }

    /**
     * Method checks whether the annotation as well as possible comments attached to it
     * belong to the current user
     *
     * @return
     */
    public static function deletion_allowed($annotationid, $cmid) {

        global $DB;
        $table = 'pdfannotator_annotations';

        global $USER;
        $thisuser = $USER->id;
        $annotationauthor = self::get_author($annotationid);

        $result = [];

        // If user has admin rights with regard to annotations/comments: Allow deletion.
        if (!$cm = get_coursemodule_from_id('pdfannotator', $cmid)) {
            error("Course module ID was incorrect");
        }
        $context = context_module::instance($cm->id);

        if (has_capability('mod/pdfannotator:administrateuserinput', $context)) {
            $result[] = true;
            return $result;
        }

        // If not:
        // Check user permission to delete the annotation itself.
        if ($thisuser != $annotationauthor) {
            $result[] = false;
            $result[] = get_string('onlyDeleteOwnAnnotations', 'pdfannotator');
            return $result;
        }
        // Check whether other people have commented this annotation.
        $attachedcomments = pdfannotator_comment::find($annotationid);
        if ($attachedcomments && $attachedcomments !== null) {
            foreach ($attachedcomments as $comment) {
                if ($thisuser != $comment->userid) {
                    $result[] = false;
                    $result[] = get_string('onlyDeleteUncommentedPosts', 'pdfannotator');
                    return $result;
                }
            }
        }

        $result[] = true;
        return $result;
    }

    /**
     * Method checks whether the annotation in question may be shifted in position.
     * It returns true if the annotation was made by the user who is trying to shift it
     * and not yet commented by other people.
     *
     * @global type $USER
     * @param type $annotationId
     * @return boolean
     */
    public static function shifting_allowed($annotationid) {

        global $DB;
        global $USER;

        $annotationauthor = self::get_author($annotationid);

        // Check user permission to delete the annotation itself.
        if ($USER->id != $annotationauthor) {
            return false;
        }

        // Check whether other people have commented this annotation.
        $params = array($annotationid, $USER->id);
        if ($DB->record_exists_select('pdfannotator_comments', "annotationid = ? AND userid != ?", $params)) {
            return false;
        }
        return true;
    }

    /**
     * Method takes an annotation's id and returns the user id of its author
     *
     * @global type $DB
     * @param type $itemid
     * @return type
     */
    public static function get_author($annotationid) {

        global $DB;
        return $DB->get_field('pdfannotator_annotations', 'userid', array('id' => $annotationid), $strictness = MUST_EXIST);
    }

    /**
     * Method takes an annotation's id and returns the page it was made on
     *
     * @global type $DB
     * @param type $annotationId
     * @return type
     */
    public static function get_pageid($annotationid) {
        global $DB;
        return $DB->get_field('pdfannotator_annotations', 'page', array('id' => $annotationid), $strictness = IGNORE_MISSING);
    }

    /**
     * Method takes an annotation's id and returns the content of the underlying question comment
     *
     * @global type $DB
     * @param type $annotationId
     * @return type
     */
    public static function get_question($annotationid) {
        global $DB;
        $question = $DB->get_record('pdfannotator_comments', ['annotationid' => $annotationid, 'isquestion' => 1], 'content');
        return $question->content;
    }

}
