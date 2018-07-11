<?php

/**
 * @package   mod_pdfannotator
 * @copyright 2018 RWTH Aachen, Friederike Schwager (see README.md)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

/**
 * This class contains functions returning the data for the statistics-tab
 * @author schwager
 */
class pdfannotator_statistics {

    private $courseid;
    private $annotatorid;
    private $userid;
    private $isteacher;

    public function __construct($courseid, $annotatorid, $userid, $isteacher = false) {
        $this->annotatorid = $annotatorid;
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->isteacher = $isteacher;
    }

    /**
     * Returns the number of questions/answers in one PDF-Annotator by one/all users
     * @global type $DB
     * @param type $isquestion  '1' for questions, '0' for answers
     * @param type $user   false by default for comments by all users. True for comments by the user
     * @return type
     */
    public function get_comments_annotator($isquestion, $user = false) {
        global $DB;

        $conditions = array('pdfannotatorid' => $this->annotatorid, 'isquestion' => $isquestion, 'isdeleted' => '0');
        if ($user) {
            $conditions['userid'] = $this->userid;
        }

        return $DB->count_records('pdfannotator_comments', $conditions);
    }

    /**
     * Returns the number of questions/answers in all PDF-Annotators in one course by one/all users
     * @global type $DB
     * @param type $isquestion  '1' for questions, '0' for answers
     * @param type $user false by default for comments by all users. userid for comments by a specific user
     * @return type
     */
    public function get_comments_course($isquestion, $user = false) {
        global $DB;
        $sql = 'SELECT COUNT(*) FROM {pdfannotator_comments} c JOIN {pdfannotator} a ON '
                . 'a.course = ? AND a.id = c.pdfannotatorid WHERE c.isquestion = ? AND c.isdeleted = ?';
        if ($user) {
            $sql .= " AND c.userid = ?";
        }
        return $DB->count_records_sql($sql, array($this->courseid, $isquestion, '0', $this->userid));
    }

    /**
     * Returns the average number of questions/answers a user wrote in this pdf-annotator.
     * Only users that wrote at least one comment are included.
     * @global type $DB
     * @param type $isquestion '1' for questions, '0' for answers
     * @return type
     */
    public function get_comments_average_annotator($isquestion) {
        global $DB;
        $sql = "SELECT AVG(count) AS average FROM ("
                . "SELECT COUNT(*) AS count FROM {pdfannotator_comments} "
                . "WHERE pdfannotatorid=? AND isquestion = ? AND isdeleted = ? "
                . "GROUP BY userid ) AS counts";

        return key($DB->get_records_sql($sql, array($this->annotatorid, $isquestion, '0')));
    }

    /**
     * Returns the average number of questions/answers a user wrote in this course.
     * Only users that wrote at least one comment are included.
     * @global type $DB
     * @param type $isquestion '1' for questions, '0' for answers
     * @return type
     */
    public function get_comments_average_course($isquestion) {
        global $DB;
        $sql = "SELECT AVG(count) AS average FROM ("
                . "SELECT COUNT(*) AS count "
                . "FROM {pdfannotator_comments} c, {pdfannotator} a "
                . "WHERE a.course = ? AND a.id = c.pdfannotatorid AND c.isquestion = ? AND c.isdeleted = ?"
                . "GROUP BY c.userid ) AS counts";

        return key($DB->get_records_sql($sql, array($this->courseid, $isquestion, '0')));
    }

    /**
     * Returns the number of reported comments in this annotator.
     * @global type $DB
     * @return type
     */
    public function get_reports_annotator() {
        global $DB;
        return $DB->count_records('pdfannotator_reports', array('pdfannotatorid' => $this->annotatorid));
    }

    /**
     * Returns the number of reported comments in this course.
     * @global type $DB
     * @return type
     */
    public function get_reports_course() {
        global $DB;
        return $DB->count_records('pdfannotator_reports', array('courseid' => $this->courseid));
    }

    /**
     * Returns the data for the (left) table in the statistics-tab.
     * @return array
     */
    public function get_tabledata_1() {
        $ret = [];

        if ($this->isteacher) {
            $ret[] = array('i' => array(get_string('questions', 'pdfannotator'), $this->get_comments_annotator('1'), $this->get_comments_course('1')));
            $ret[] = array('i' => array(get_string('answers', 'pdfannotator'), $this->get_comments_annotator('0'), $this->get_comments_course('0')));
            $ret[] = array('i' => array(get_string('myanswers', 'pdfannotator'), $this->get_comments_annotator('0', true), $this->get_comments_course('0', true)));
            $ret[] = array('i' => array(get_string('reports', 'pdfannotator'), $this->get_reports_annotator(), $this->get_reports_course()));
        } else {
            $ret[] = array('i' => array(get_string('questions', 'pdfannotator'), $this->get_comments_annotator('1'),
                $this->get_comments_annotator('1', true), round($this->get_comments_average_annotator('1'), 2)));
            $ret[] = array('i' => array(get_string('answers', 'pdfannotator'), $this->get_comments_annotator('0'),
                $this->get_comments_annotator('0', true), round($this->get_comments_average_annotator('0'), 2)));
        }
        return $ret;
    }

    /**
     * Returns the data for the right table in the statistics-tab for students.
     * @return array
     */
    public function get_tabledata_2() {
        $ret = [];

        $ret[] = array('i' => array(get_string('questions', 'pdfannotator'), $this->get_comments_course('1'),
                $this->get_comments_course('1', true), round($this->get_comments_average_course('1'), 2)));
        $ret[] = array('i' => array(get_string('answers', 'pdfannotator'), $this->get_comments_course('0'),
                $this->get_comments_course('0', true), round($this->get_comments_average_course('0'), 2)));

        return $ret;
    }

    /**
     * Returns the data for the chart in the statistics-tab.
     * @global type $DB
     * @param type $pdfannotators
     * @return type
     */
    public function get_chartdata() {
        global $DB;

        $pdfannotators = pdfannotator_instance::get_pdfannotator_instances($this->courseid);

        $names = [];
        $otheranswers = [];
        $myanswers = [];
        $otherquestions = [];
        $myquestions = [];
        foreach ($pdfannotators as $pdfannotator) {
            $countquestions = self::count_comments_annotator($pdfannotator->get_id(), '1');
            $countmyquestions = self::count_comments_annotator($pdfannotator->get_id(), '1', $this->userid);
            $countanswers = self::count_comments_annotator($pdfannotator->get_id(), '0');
            $countmyanswers = self::count_comments_annotator($pdfannotator->get_id(), '0', $this->userid);

            $otherquestions[] = $countquestions - $countmyquestions;
            $myquestions[] = $countmyquestions;
            $otheranswers[] = $countanswers - $countmyanswers;
            $myanswers[] = $countmyanswers;
            $names[] = $pdfannotator->get_name();
        }
        $ret = array($names, $otherquestions, $myquestions, $otheranswers, $myanswers);
        return $ret;
    }
  
    /**
     * Returns the number of questions/answers in one PDF-Annotator by one/all users
     * @global type $DB
     * @param type $annotatorid
     * @param type $isquestion '1' for questions, '0' for answers
     * @param type $userid false by default for comments by all users. Userid for comments by a specific user
     * @return type
     */
    public static function count_comments_annotator($annotatorid, $isquestion, $userid = false) {
        global $DB;

        $conditions = array('pdfannotatorid' => $annotatorid, 'isquestion' => $isquestion, 'isdeleted' => '0');
        if ($userid) {
            $conditions['userid'] = $userid;
        }

        return $DB->count_records('pdfannotator_comments', $conditions);
    }

}
